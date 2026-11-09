<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Tests\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Macpaw\PostgresSchemaBundle\Doctrine\Middleware\TransactionSearchPathConnection;
use Macpaw\PostgresSchemaBundle\Exception\TransactionSearchPathModeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the safety net for transaction control that the SQL classification misses: the server transaction
 * status is simulated, because real PostgreSQL never disagrees with SQL the classification understands.
 */
final class TransactionSearchPathConnectionTest extends TestCase
{
    private const PREVIEW_SCHEMA = '"preview_a"';
    private const APPLY_IMPLICIT = 'BEGIN; SET LOCAL search_path TO "preview_a"';
    private const INSERT = 'INSERT INTO marker (v) VALUES (1)';

    /** @var list<string> */
    private array $sent = [];

    private bool $isServerInTransaction = false;

    /** @var array<string, bool> server transaction status after each SQL */
    private array $serverTransactionAfter = [];

    public function testImplicitTransactionClosedByUndetectedControlIsRejectedWithoutCommit(): void
    {
        $connection = $this->connection(self::PREVIEW_SCHEMA);
        $this->serverTransactionAfter[self::INSERT] = false;

        $this->expectRawTransactionControl(fn () => $connection->exec(self::INSERT));

        self::assertSame([self::APPLY_IMPLICIT, self::INSERT, 'ROLLBACK'], $this->sent);
    }

    public function testTransactionLeftOpenByUndetectedControlIsRolledBack(): void
    {
        $connection = $this->connection(null);
        $this->serverTransactionAfter[self::INSERT] = true;

        $this->expectRawTransactionControl(fn () => $connection->exec(self::INSERT));

        self::assertSame([self::INSERT, 'ROLLBACK'], $this->sent);
        self::assertFalse($this->isServerInTransaction);
    }

    public function testStatementsAfterCallerTransactionWasLostAreNotSent(): void
    {
        $connection = $this->connection(self::PREVIEW_SCHEMA);
        $connection->beginTransaction();
        $this->serverTransactionAfter[self::INSERT] = false;

        $this->expectRawTransactionControl(fn () => $connection->exec(self::INSERT));
        $this->expectRawTransactionControl(fn () => $connection->exec('SELECT 1'));

        self::assertSame(['SET LOCAL search_path TO "preview_a"', self::INSERT], $this->sent);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unclassifiableSqlProvider(): iterable
    {
        yield 'unterminated string' => ["SELECT 1; SELECT 'open"];
        yield 'unterminated identifier' => ['SELECT 1; SELECT "open'];
        yield 'unterminated block comment' => ['SELECT 1; /* open COMMIT'];
        yield 'stray comment end' => ['SELECT 1 */; COMMIT'];
        yield 'unterminated dollar quote' => ['SELECT $body$ x; COMMIT'];
    }

    #[DataProvider('unclassifiableSqlProvider')]
    public function testUnclassifiableSqlIsRejectedBeforeSending(string $sql): void
    {
        $connection = $this->connection(self::PREVIEW_SCHEMA);

        foreach ([fn () => $connection->exec($sql), fn () => $connection->prepare($sql)] as $call) {
            try {
                $call();
                self::fail('Unclassifiable SQL must be rejected.');
            } catch (TransactionSearchPathModeException $exception) {
                self::assertStringContainsString('cannot split this SQL', $exception->getMessage());
            }
        }

        self::assertSame([], $this->sent);
    }

    public function testPcreFailureIsRejectedBeforeSending(): void
    {
        $connection = $this->connection(self::PREVIEW_SCHEMA);
        $backtrackLimit = ini_get('pcre.backtrack_limit');
        $jit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');

        try {
            $this->expectExceptionObject(TransactionSearchPathModeException::unclassifiableSql());
            $connection->exec("SELECT 1; SELECT \$x\$ long body; with semicolons \$x\$");
        } finally {
            ini_set('pcre.backtrack_limit', (string) $backtrackLimit);
            ini_set('pcre.jit', (string) $jit);
            self::assertSame([], $this->sent);
        }
    }

    public function testMatchingServerTransactionStatusRunsAsBefore(): void
    {
        $connection = $this->connection(self::PREVIEW_SCHEMA);

        $connection->exec(self::INSERT);
        $connection->beginTransaction();
        $connection->exec(self::INSERT);
        $connection->commit();

        self::assertSame(
            [
                self::APPLY_IMPLICIT,
                self::INSERT,
                'COMMIT',
                'SET LOCAL search_path TO "preview_a"',
                self::INSERT,
            ],
            $this->sent,
        );
    }

    private function expectRawTransactionControl(callable $call): void
    {
        try {
            $call();
            self::fail('Undetected transaction control must be rejected.');
        } catch (TransactionSearchPathModeException $exception) {
            self::assertSame(
                TransactionSearchPathModeException::rawTransactionControl()->getMessage(),
                $exception->getMessage(),
            );
        }
    }

    private function connection(?string $schema): TransactionSearchPathConnection
    {
        $wrapped = $this->createMock(DriverConnection::class);
        $wrapped->method('exec')->willReturnCallback(function (string $sql): int {
            $this->sent[] = $sql;
            $this->isServerInTransaction = match (true) {
                isset($this->serverTransactionAfter[$sql]) => $this->serverTransactionAfter[$sql],
                str_starts_with($sql, 'BEGIN') => true,
                $sql === 'COMMIT', $sql === 'ROLLBACK' => false,
                default => $this->isServerInTransaction,
            };

            return 0;
        });
        $wrapped->method('beginTransaction')->willReturnCallback(function (): bool {
            $this->isServerInTransaction = true;

            return true;
        });
        $wrapped->method('commit')->willReturnCallback(function (): bool {
            $this->isServerInTransaction = false;

            return true;
        });

        return new TransactionSearchPathConnection(
            $wrapped,
            static fn (): ?string => $schema,
            static function (): void {
            },
            fn (): bool => $this->isServerInTransaction,
        );
    }
}
