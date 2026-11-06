<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Doctrine\Middleware;

use Closure;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Throwable;

/**
 * Applies search_path with SET LOCAL, so it never outlives the transaction and cannot leak to other clients
 * through a PgBouncer server connection in transaction pooling mode.
 *
 * While $isActive returns false every statement is sent unchanged; only transaction control SQL is tracked.
 */
final class TransactionSearchPathConnection extends AbstractConnectionMiddleware
{
    /** Leading whitespace and SQL comments before the first keyword. */
    private const PREFIX = '/^(?:\s+|\/\*.*?\*\/|--[^\n]*(?:\n|$))*';
    private const BEGIN_PATTERN = self::PREFIX . '(?:BEGIN|START\s+TRANSACTION)\b/is';
    private const ROLLBACK_TO_PATTERN = self::PREFIX . 'ROLLBACK\s+(?:(?:WORK|TRANSACTION)\s+)?TO\b/is';
    private const END_PATTERN = self::PREFIX . '(?:COMMIT|END|ABORT|ROLLBACK)\b/is';
    private const SAVEPOINT_PATTERN = self::PREFIX . '(?:SAVEPOINT|RELEASE)\b/is';

    private const CONTROL_BEGIN = 'begin';
    private const CONTROL_END = 'end';
    private const CONTROL_ROLLBACK_TO = 'rollback_to';
    private const CONTROL_SAVEPOINT = 'savepoint';

    private bool $inTransaction = false;
    private ?string $appliedSchema = null;
    private bool $isAppliedSchemaKnown = true;

    /**
     * @param Closure(): bool             $isActive       whether transaction search_path mode is on
     * @param Closure(): ?string          $schemaProvider returns the quoted schema to apply,
     *                                                    or null to keep the server default
     * @param Closure(string, bool): void $onApply        called with the quoted schema and whether
     *                                                    the transaction was opened by the caller
     */
    public function __construct(
        Connection $wrappedConnection,
        private readonly Closure $isActive,
        private readonly Closure $schemaProvider,
        private readonly Closure $onApply,
    ) {
        parent::__construct($wrappedConnection);
    }

    public function prepare(string $sql): Statement
    {
        return new TransactionSearchPathStatement(parent::prepare($sql), $this);
    }

    public function query(string $sql): Result
    {
        $control = $this->classifyTransactionControl($sql);

        if ($control !== null) {
            return $this->runTransactionControl($control, fn (): Result => parent::query($sql));
        }

        return $this->runWithSearchPath(fn (): Result => parent::query($sql));
    }

    public function exec(string $sql): int
    {
        $control = $this->classifyTransactionControl($sql);

        if ($control !== null) {
            return $this->runTransactionControl($control, fn (): int => parent::exec($sql));
        }

        return $this->runWithSearchPath(fn (): int => parent::exec($sql));
    }

    public function beginTransaction(): bool
    {
        $result = parent::beginTransaction();
        $this->startTransaction();

        try {
            $this->applyInTransaction();
        } catch (Throwable $exception) {
            try {
                parent::rollBack();
            } catch (Throwable) {
            }

            $this->endTransaction();

            throw $exception;
        }

        return $result;
    }

    public function commit(): bool
    {
        try {
            return parent::commit();
        } finally {
            $this->endTransaction();
        }
    }

    public function rollBack(): bool
    {
        try {
            return parent::rollBack();
        } finally {
            $this->endTransaction();
        }
    }

    /**
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    public function runWithSearchPath(Closure $operation): mixed
    {
        if (!($this->isActive)()) {
            return $operation();
        }

        if ($this->inTransaction) {
            $this->applyInTransaction();

            return $operation();
        }

        $schema = ($this->schemaProvider)();

        if ($schema === null) {
            return $operation();
        }

        return $this->runInImplicitTransaction($schema, $operation);
    }

    private function applyInTransaction(): void
    {
        if (!($this->isActive)()) {
            return;
        }

        $schema = ($this->schemaProvider)();

        if ($this->isAppliedSchemaKnown && $schema === $this->appliedSchema) {
            return;
        }

        parent::exec('SET LOCAL search_path TO ' . ($schema ?? 'DEFAULT'));
        $this->appliedSchema = $schema;
        $this->isAppliedSchemaKnown = true;
        ($this->onApply)($schema ?? 'DEFAULT', true);
    }

    /**
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    private function runInImplicitTransaction(string $schema, Closure $operation): mixed
    {
        try {
            parent::exec('BEGIN; SET LOCAL search_path TO ' . $schema);
            ($this->onApply)($schema, false);
            $result = $operation();
        } catch (Throwable $exception) {
            $this->rollBackQuietly();

            throw $exception;
        }

        try {
            parent::exec('COMMIT');
        } catch (Throwable $exception) {
            $this->rollBackQuietly();

            throw $exception;
        }

        return $result;
    }

    /**
     * @return self::CONTROL_*|null
     */
    private function classifyTransactionControl(string $sql): ?string
    {
        return match (true) {
            preg_match(self::BEGIN_PATTERN, $sql) === 1 => self::CONTROL_BEGIN,
            preg_match(self::ROLLBACK_TO_PATTERN, $sql) === 1 => self::CONTROL_ROLLBACK_TO,
            preg_match(self::END_PATTERN, $sql) === 1 => self::CONTROL_END,
            preg_match(self::SAVEPOINT_PATTERN, $sql) === 1 => self::CONTROL_SAVEPOINT,
            default => null,
        };
    }

    /**
     * Transaction control SQL runs as is in every mode: no SET LOCAL before it (it would fail in an aborted
     * transaction and block the recovery) and no implicit transaction around it (it would end the caller's
     * transaction). Its effect is tracked even while the mode is inactive, so the state stays correct
     * if the mode is switched on later.
     *
     * @template T
     *
     * @param self::CONTROL_* $control
     * @param Closure(): T    $operation
     *
     * @return T
     */
    private function runTransactionControl(string $control, Closure $operation): mixed
    {
        $result = $operation();

        match ($control) {
            self::CONTROL_BEGIN => $this->startTransaction(),
            self::CONTROL_END => $this->endTransaction(),
            // A SET LOCAL issued after the savepoint is undone with it, so re-apply before the next query.
            self::CONTROL_ROLLBACK_TO => $this->isAppliedSchemaKnown = false,
            self::CONTROL_SAVEPOINT => null,
        };

        return $result;
    }

    private function startTransaction(): void
    {
        $this->inTransaction = true;
        $this->appliedSchema = null;
        $this->isAppliedSchemaKnown = true;
    }

    private function endTransaction(): void
    {
        $this->inTransaction = false;
        $this->appliedSchema = null;
        $this->isAppliedSchemaKnown = true;
    }

    private function rollBackQuietly(): void
    {
        try {
            parent::exec('ROLLBACK');
        } catch (Throwable) {
        }
    }
}
