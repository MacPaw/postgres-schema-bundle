<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Doctrine\Middleware;

use Closure;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Macpaw\PostgresSchemaBundle\Exception\TransactionSearchPathModeException;
use Throwable;

/**
 * Applies search_path with SET LOCAL, so it never outlives the transaction and cannot leak to other clients
 * through a PgBouncer server connection in transaction pooling mode.
 *
 * Transactions must be managed through the DBAL API (beginTransaction(), commit(), rollBack(), transactional()).
 * Raw BEGIN/COMMIT/ROLLBACK SQL is rejected; savepoint SQL, which DBAL sends for nested transactions, is allowed
 * as a single statement. Transaction control or savepoint SQL batched with other statements is rejected.
 */
final class TransactionSearchPathConnection extends AbstractConnectionMiddleware
{
    /**
     * Parts of SQL that may contain ';' without ending a statement, removed before splitting on ';':
     * E'' escape strings, string literals, quoted identifiers, dollar-quoted bodies, nested block comments
     * and line comments.
     */
    private const NON_CODE_PATTERN = '/[Ee]\'(?:\\\\.|\'\'|[^\'\\\\])*\''
        . '|\'(?:\'\'|[^\'])*\''
        . '|"(?:""|[^"])*"'
        . '|\$(?<tag>\w*)\$.*?\$\k<tag>\$'
        . '|(?<comment>\/\*(?:[^\/*]|\/(?!\*)|\*(?!\/)|(?&comment))*\*\/)'
        . '|--[^\n]*/s';
    private const PREPARED_2PC_PATTERN = '/^(?:COMMIT|ROLLBACK)\s+PREPARED\b/i';
    private const ROLLBACK_TO_PATTERN = '/^ROLLBACK\s+(?:(?:WORK|TRANSACTION)\s+)?TO\b/i';
    private const SAVEPOINT_PATTERN = '/^(?:SAVEPOINT|RELEASE)\b/i';
    private const TRANSACTION_CONTROL_PATTERN
        = '/^(?:BEGIN|START\s+TRANSACTION|COMMIT|END|ABORT|ROLLBACK|PREPARE\s+TRANSACTION)\b/i';

    private const KIND_SAVEPOINT = 'savepoint';
    private const KIND_ROLLBACK_TO = 'rollback_to';
    private const KIND_CONTROL = 'control';
    private const KIND_PASSTHROUGH = 'passthrough';

    private bool $inTransaction = false;
    private ?string $appliedSchema = null;
    private bool $isAppliedSchemaKnown = true;

    /**
     * @param Closure(): ?string          $schemaProvider returns the quoted schema to apply,
     *                                                    or null to keep the server default
     * @param Closure(string, bool): void $onApply        called with the quoted schema and whether
     *                                                    the transaction was opened by the caller
     */
    public function __construct(
        Connection $wrappedConnection,
        private readonly Closure $schemaProvider,
        private readonly Closure $onApply,
    ) {
        parent::__construct($wrappedConnection);
    }

    public function prepare(string $sql): Statement
    {
        if ($this->classify($sql) !== null) {
            throw TransactionSearchPathModeException::rawTransactionControl();
        }

        return new TransactionSearchPathStatement(parent::prepare($sql), $this);
    }

    public function query(string $sql): Result
    {
        return $this->run($sql, fn (): Result => parent::query($sql));
    }

    public function exec(string $sql): int
    {
        return $this->run($sql, fn (): int => parent::exec($sql));
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
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    private function run(string $sql, Closure $operation): mixed
    {
        return match ($this->classify($sql)) {
            null => $this->runWithSearchPath($operation),
            self::KIND_CONTROL => throw TransactionSearchPathModeException::rawTransactionControl(),
            // No SET LOCAL before savepoint SQL: in an aborted transaction it would fail and block the recovery.
            self::KIND_SAVEPOINT, self::KIND_PASSTHROUGH => $operation(),
            self::KIND_ROLLBACK_TO => $this->runRollbackToSavepoint($operation),
        };
    }

    /**
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    private function runRollbackToSavepoint(Closure $operation): mixed
    {
        try {
            return $operation();
        } finally {
            // A SET LOCAL issued after the savepoint is undone with it, so re-apply before the next query.
            $this->isAppliedSchemaKnown = false;
        }
    }

    /**
     * Classifies transaction control in a statement or a batch. Null means ordinary SQL.
     *
     * @return self::KIND_*|null
     */
    private function classify(string $sql): ?string
    {
        // Fast path for the common case: one statement without a leading comment.
        if (!str_contains($sql, ';') && preg_match('/^\s*(?:--|\/\*)/', $sql) !== 1) {
            return $this->classifyStatement(ltrim($sql));
        }

        $code = (string) preg_replace(self::NON_CODE_PATTERN, ' ', $sql);
        $statements = array_values(array_filter(
            array_map(trim(...), explode(';', $code)),
            static fn (string $statement): bool => $statement !== '',
        ));
        $kinds = array_map($this->classifyStatement(...), $statements);
        $controlKinds = array_filter($kinds, static fn (?string $kind): bool => $kind !== null);

        if ($controlKinds === []) {
            return null;
        }

        // Statements after transaction control in a batch would run without SET LOCAL or end the transaction.
        return count($statements) === 1 ? $controlKinds[0] : self::KIND_CONTROL;
    }

    /**
     * @return self::KIND_*|null
     */
    private function classifyStatement(string $statement): ?string
    {
        return match (true) {
            preg_match(self::PREPARED_2PC_PATTERN, $statement) === 1 => self::KIND_PASSTHROUGH,
            preg_match(self::ROLLBACK_TO_PATTERN, $statement) === 1 => self::KIND_ROLLBACK_TO,
            preg_match(self::SAVEPOINT_PATTERN, $statement) === 1 => self::KIND_SAVEPOINT,
            preg_match(self::TRANSACTION_CONTROL_PATTERN, $statement) === 1 => self::KIND_CONTROL,
            default => null,
        };
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
