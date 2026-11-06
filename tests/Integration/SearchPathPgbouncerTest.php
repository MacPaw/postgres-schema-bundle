<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DBALException;
use Macpaw\PostgresSchemaBundle\Doctrine\SchemaConnection;
use Macpaw\PostgresSchemaBundle\Doctrine\SearchPathMode;
use Macpaw\SchemaContextBundle\Logger\DebugLogger;
use Macpaw\SchemaContextBundle\Service\BaggageSchemaResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Runs against PostgreSQL behind PgBouncer in transaction pooling mode with a pool of one server connection,
 * so every client shares the same server session (see docker-compose.yml).
 */
final class SearchPathPgbouncerTest extends TestCase
{
    private const PREVIEW_SCHEMA = 'preview_a';
    private const PUBLIC_MARKER = 'public';
    private const PREVIEW_MARKER = 'preview';
    private const MARKER_QUERY = 'SELECT v FROM marker';

    private BaggageSchemaResolver $resolver;

    /** @var list<Connection> */
    private array $connections = [];

    public static function setUpBeforeClass(): void
    {
        if (self::url('DIRECT_DATABASE_URL') === null || self::url('PGBOUNCER_DATABASE_URL') === null) {
            return;
        }

        $direct = DriverManager::getConnection(self::params('DIRECT_DATABASE_URL'));
        $previewSchema = $direct->quoteIdentifier(self::PREVIEW_SCHEMA);

        $direct->executeStatement('DROP SCHEMA IF EXISTS ' . $previewSchema . ' CASCADE');
        $direct->executeStatement('DROP TABLE IF EXISTS public.marker');
        $direct->executeStatement('CREATE SCHEMA ' . $previewSchema);
        $direct->executeStatement('CREATE TABLE public.marker (id serial PRIMARY KEY, v text NOT NULL)');
        $direct->executeStatement(
            'CREATE TABLE ' . $previewSchema . '.marker (id serial PRIMARY KEY, v text NOT NULL)',
        );
        $direct->insert('public.marker', ['v' => self::PUBLIC_MARKER]);
        $direct->insert(self::PREVIEW_SCHEMA . '.marker', ['v' => self::PREVIEW_MARKER]);
        $direct->close();
    }

    protected function setUp(): void
    {
        if (self::url('PGBOUNCER_DATABASE_URL') === null || self::url('DIRECT_DATABASE_URL') === null) {
            self::markTestSkipped(
                'PGBOUNCER_DATABASE_URL and DIRECT_DATABASE_URL must be set; run via docker compose.',
            );
        }

        $this->resetServerSessions();
        $this->resolver = new BaggageSchemaResolver('public', 'development', ['development'], new DebugLogger());
        SchemaConnection::setSchemaResolver($this->resolver);
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }

        $this->connections = [];
        SchemaConnection::setSearchPathMode(SearchPathMode::Session);
        SchemaConnection::setLogger(new DebugLogger());
    }

    public function testSessionModeLeaksPreviewSchemaToDefaultClient(): void
    {
        $default = $this->connect();
        $preview = $this->connect();

        self::assertSame(self::PUBLIC_MARKER, $this->readMarker($default, null));
        self::assertSame(self::PREVIEW_MARKER, $this->readMarker($preview, self::PREVIEW_SCHEMA));

        self::assertSame(
            self::PREVIEW_MARKER,
            $this->readMarker($default, null),
            'Session SET search_path is expected to leak behind transaction pooling.',
        );
    }

    public function testSessionModeKeepsSessionSearchPathAfterSavepointRollback(): void
    {
        $preview = $this->connect();

        self::assertSame(self::PREVIEW_MARKER, $this->readMarker($preview, self::PREVIEW_SCHEMA));
        $preview->beginTransaction();
        $preview->executeStatement('SAVEPOINT raw_sp');
        $preview->executeStatement('ROLLBACK TO SAVEPOINT raw_sp');

        self::assertSame(self::PREVIEW_MARKER, $preview->fetchOne(self::MARKER_QUERY));
        $preview->commit();
    }

    public function testLenientTransactionModeKeepsDefaultClientOnServerDefaultSchema(): void
    {
        SchemaConnection::setSearchPathMode(SearchPathMode::Transaction, 'public');
        $default = $this->connect();
        $preview = $this->connect();

        self::assertSame(self::PUBLIC_MARKER, $this->readMarker($default, null));
        self::assertSame(self::PREVIEW_MARKER, $this->readMarker($preview, self::PREVIEW_SCHEMA));
        self::assertSame(self::PUBLIC_MARKER, $this->readMarker($default, null));
        self::assertSame('"$user", public', $default->fetchOne('SHOW search_path'));
    }

    public function testStrictTransactionModeIgnoresSearchPathLeakedByASessionClient(): void
    {
        $this->leakSessionSearchPath(self::PREVIEW_SCHEMA);
        SchemaConnection::setSearchPathMode(SearchPathMode::Transaction);
        $default = $this->connect();

        self::assertSame(self::PUBLIC_MARKER, $this->readMarker($default, null));
    }

    public function testLenientTransactionModeInheritsSearchPathLeakedByASessionClient(): void
    {
        $this->leakSessionSearchPath(self::PREVIEW_SCHEMA);
        SchemaConnection::setSearchPathMode(SearchPathMode::Transaction, 'public');
        $default = $this->connect();

        self::assertSame(
            self::PREVIEW_MARKER,
            $this->readMarker($default, null),
            'Skipping SET LOCAL for the server default relies on no client setting a session search_path.',
        );
    }

    public function testTransactionModeAppliesSchemaToPreparedStatements(): void
    {
        SchemaConnection::setSearchPathMode(SearchPathMode::Transaction);
        $default = $this->connect();
        $preview = $this->connect();
        $this->resolver->setSchema(self::PREVIEW_SCHEMA);

        $marker = $preview->prepare(self::MARKER_QUERY . ' WHERE id = ?')->executeQuery([1])->fetchOne();

        self::assertSame(self::PREVIEW_MARKER, $marker);
        self::assertSame(self::PUBLIC_MARKER, $this->readMarker($default, null));
    }

    public function testTransactionModeWritesExplicitTransactionToResolvedSchema(): void
    {
        SchemaConnection::setSearchPathMode(SearchPathMode::Transaction);
        $preview = $this->connect();
        $this->resolver->setSchema(self::PREVIEW_SCHEMA);

        $preview->transactional(static function (Connection $connection): void {
            $connection->insert('marker', ['v' => 'tx-write']);
            $connection->executeStatement('UPDATE marker SET v = v WHERE v = ?', ['tx-write']);
        });

        self::assertSame(1, $this->countDirect(self::PREVIEW_SCHEMA, 'tx-write'));
        self::assertSame(0, $this->countDirect('public', 'tx-write'));
    }

    public function testTransactionModeReappliesSchemaAfterSavepointRollback(): void
    {
        SchemaConnection::setSearchPathMode(SearchPathMode::Transaction);
        $preview = $this->connect();
        $preview->setNestTransactionsWithSavepoints(true);

        $this->resolver->setSchema(null);
        $preview->beginTransaction();
        $preview->beginTransaction();
        self::assertSame(self::PREVIEW_MARKER, $this->readMarker($preview, self::PREVIEW_SCHEMA));
        $preview->rollBack();

        self::assertSame(self::PREVIEW_MARKER, $preview->fetchOne(self::MARKER_QUERY));
        $preview->commit();
    }

    public function testTransactionModeRollsBackSavepointOfAbortedTransactionAfterSchemaChange(): void
    {
        SchemaConnection::setSearchPathMode(SearchPathMode::Transaction);
        $preview = $this->connect();
        $preview->setNestTransactionsWithSavepoints(true);
        $this->resolver->setSchema(self::PREVIEW_SCHEMA);

        $preview->beginTransaction();
        $preview->beginTransaction();

        try {
            $preview->fetchOne('SELECT missing_column FROM marker');
            self::fail('Query with a missing column must fail.');
        } catch (DBALException) {
        }

        $this->resolver->setSchema(null);
        $preview->rollBack();

        self::assertSame(self::PUBLIC_MARKER, $preview->fetchOne(self::MARKER_QUERY));
        $preview->commit();
    }

    public function testTransactionModeKeepsRawTransactionControlStatements(): void
    {
        SchemaConnection::setSearchPathMode(SearchPathMode::Transaction);
        $preview = $this->connect();
        $this->resolver->setSchema(self::PREVIEW_SCHEMA);

        $preview->executeStatement('START TRANSACTION');
        $preview->insert('marker', ['v' => 'raw-tx']);
        $preview->executeStatement('ROLLBACK');

        self::assertSame(0, $this->countDirect(self::PREVIEW_SCHEMA, 'raw-tx'));
        self::assertSame(self::PREVIEW_MARKER, $preview->fetchOne(self::MARKER_QUERY));
    }

    public function testTransactionModeClosesImplicitTransactionWhenApplyFails(): void
    {
        SchemaConnection::setSearchPathMode(SearchPathMode::Transaction);
        SchemaConnection::setLogger(new DebugLogger(self::failingApplyLogger()));
        $preview = $this->connect();
        $this->resolver->setSchema(self::PREVIEW_SCHEMA);

        try {
            $preview->fetchOne(self::MARKER_QUERY);
            self::fail('The failing logger must abort the query.');
        } catch (RuntimeException) {
        }

        self::assertSame(0, $this->countOpenServerTransactions());
    }

    public function testTransactionModeClosesExplicitTransactionWhenApplyFails(): void
    {
        SchemaConnection::setSearchPathMode(SearchPathMode::Transaction);
        $preview = $this->connect();
        $preview->fetchOne('SELECT 1');
        SchemaConnection::setLogger(new DebugLogger(self::failingApplyLogger()));
        $this->resolver->setSchema(self::PREVIEW_SCHEMA);

        try {
            $preview->beginTransaction();
            self::fail('The failing logger must abort beginTransaction().');
        } catch (RuntimeException) {
        }

        self::assertSame(0, $this->countOpenServerTransactions());
    }

    public function testTransactionModeRecoversAfterFailedStatement(): void
    {
        SchemaConnection::setSearchPathMode(SearchPathMode::Transaction);
        $default = $this->connect();
        $preview = $this->connect();
        $this->resolver->setSchema(self::PREVIEW_SCHEMA);

        try {
            $preview->fetchOne('SELECT missing_column FROM marker');
            self::fail('Query with a missing column must fail.');
        } catch (DBALException) {
        }

        self::assertSame(self::PREVIEW_MARKER, $preview->fetchOne(self::MARKER_QUERY));
        self::assertSame(self::PUBLIC_MARKER, $this->readMarker($default, null));
    }

    private static function failingApplyLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @param array<mixed> $context */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                if ((string) $message === 'schema_context_apply_search_path') {
                    throw new RuntimeException('Logger failure');
                }
            }
        };
    }

    private function countOpenServerTransactions(): int
    {
        $direct = DriverManager::getConnection(self::params('DIRECT_DATABASE_URL'));
        $this->connections[] = $direct;

        return (int) $direct->fetchOne(
            "SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND state LIKE 'idle in%'",
        );
    }

    private function leakSessionSearchPath(string $schema): void
    {
        $plain = DriverManager::getConnection(self::params('PGBOUNCER_DATABASE_URL'));
        $this->connections[] = $plain;
        $plain->executeStatement('SET search_path TO ' . $plain->quoteIdentifier($schema));
    }

    private function resetServerSessions(): void
    {
        $direct = DriverManager::getConnection(self::params('DIRECT_DATABASE_URL'));
        $direct->executeQuery(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity'
            . ' WHERE datname = current_database() AND pid <> pg_backend_pid() AND backend_type = ?',
            ['client backend'],
        );
        $direct->close();
    }

    private function countDirect(string $schema, string $value): int
    {
        $direct = DriverManager::getConnection(self::params('DIRECT_DATABASE_URL'));
        $this->connections[] = $direct;

        return (int) $direct->fetchOne(
            'SELECT count(*) FROM ' . $direct->quoteIdentifier($schema) . '.marker WHERE v = ?',
            [$value],
        );
    }

    private function readMarker(Connection $connection, ?string $schema): mixed
    {
        $this->resolver->setSchema($schema);

        return $connection->fetchOne(self::MARKER_QUERY);
    }

    private function connect(): Connection
    {
        $params = self::params('PGBOUNCER_DATABASE_URL');
        $params['wrapperClass'] = SchemaConnection::class;

        $connection = DriverManager::getConnection($params);
        $this->connections[] = $connection;

        return $connection;
    }

    private static function url(string $name): ?string
    {
        $url = getenv($name);

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @return array{driver: 'pdo_pgsql', host: string, port: int, user: string, password: string, dbname: string}
     */
    private static function params(string $name): array
    {
        $parts = parse_url((string) self::url($name));

        return [
            'driver' => 'pdo_pgsql',
            'host' => (string) ($parts['host'] ?? ''),
            'port' => (int) ($parts['port'] ?? 5432),
            'user' => (string) ($parts['user'] ?? ''),
            'password' => (string) ($parts['pass'] ?? ''),
            'dbname' => ltrim((string) ($parts['path'] ?? ''), '/'),
        ];
    }
}
