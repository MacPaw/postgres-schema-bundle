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
 * While $isActive returns false the connection passes every call through unchanged.
 */
final class TransactionSearchPathConnection extends AbstractConnectionMiddleware
{
    private const BEGIN_PATTERN = '/^\s*(BEGIN|START\s+TRANSACTION)\b/i';
    private const END_PATTERN = '/^\s*(COMMIT|END|ABORT|ROLLBACK(?!\s+TO\b))\b/i';
    private const SAVEPOINT_PATTERN = '/^\s*(SAVEPOINT|RELEASE|ROLLBACK\s+TO)\b/i';
    private const ROLLBACK_TO_PATTERN = '/^\s*ROLLBACK\s+TO\b/i';

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
        return $this->runWithSearchPath(fn (): Result => parent::query($sql));
    }

    public function exec(string $sql): int
    {
        if ($this->isTransactionControl($sql)) {
            return $this->execTransactionControl($sql);
        }

        return $this->runWithSearchPath(fn (): int => parent::exec($sql));
    }

    public function beginTransaction()
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

    public function commit()
    {
        try {
            return parent::commit();
        } finally {
            $this->endTransaction();
        }
    }

    public function rollBack()
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

        parent::exec('COMMIT');

        return $result;
    }

    private function isTransactionControl(string $sql): bool
    {
        return preg_match(self::BEGIN_PATTERN, $sql) === 1
            || preg_match(self::END_PATTERN, $sql) === 1
            || ($this->inTransaction && preg_match(self::SAVEPOINT_PATTERN, $sql) === 1);
    }

    /**
     * Transaction control SQL runs as is: no SET LOCAL before it (it would fail in an aborted transaction
     * and block the recovery) and no implicit transaction around it (it would end the caller's transaction).
     */
    private function execTransactionControl(string $sql): int
    {
        $result = parent::exec($sql);

        if (preg_match(self::BEGIN_PATTERN, $sql) === 1) {
            $this->startTransaction();
        } elseif (preg_match(self::END_PATTERN, $sql) === 1) {
            $this->endTransaction();
        } elseif (preg_match(self::ROLLBACK_TO_PATTERN, $sql) === 1) {
            // A SET LOCAL issued after the savepoint is undone with it, so re-apply before the next query.
            $this->isAppliedSchemaKnown = false;
        }

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
