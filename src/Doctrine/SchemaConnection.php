<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection as DBALConnection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Macpaw\PostgresSchemaBundle\Doctrine\Middleware\TransactionSearchPathDriver;
use Macpaw\PostgresSchemaBundle\Exception\UnsupportedPlatformException;
use Macpaw\SchemaContextBundle\Logger\DebugLogger;
use Macpaw\SchemaContextBundle\Service\BaggageSchemaResolver;
use Throwable;

/**
 * @phpstan-import-type Params from DriverManager
 */
class SchemaConnection extends DBALConnection
{
    private static ?BaggageSchemaResolver $schemaResolver = null;
    private static ?DebugLogger $logger = null;
    private static SearchPathMode $searchPathMode = SearchPathMode::Session;
    private static ?string $serverDefaultSchema = null;
    private ?string $currentSchema = null;

    /**
     * @param array<string, mixed> $params
     * @phpstan-param Params $params
     */
    public function __construct(
        array $params,
        Driver $driver,
        ?Configuration $config = null,
        ?EventManager $eventManager = null,
    ) {
        $driver = new TransactionSearchPathDriver(
            $driver,
            self::isTransactionSearchPathActive(...),
            self::resolveTransactionSearchPath(...),
            self::logTransactionSearchPath(...),
        );

        parent::__construct($params, $driver, $config, $eventManager);
    }

    /**
     * @param string|null $serverDefaultSchema null (strict, default) applies SET LOCAL to every query, ignoring any
     *                                         session search_path another client left on the pooled server
     *                                         connection. A schema name skips SET LOCAL for that schema and relies
     *                                         on the server default search_path (role/database setting).
     */
    public static function setSearchPathMode(SearchPathMode $mode, ?string $serverDefaultSchema = null): void
    {
        self::$searchPathMode = $mode;
        self::$serverDefaultSchema = $serverDefaultSchema;
    }

    public static function getSearchPathMode(): SearchPathMode
    {
        return self::$searchPathMode;
    }

    public static function setSchemaResolver(BaggageSchemaResolver $resolver): void
    {
        self::$schemaResolver = $resolver;
    }

    public static function setLogger(DebugLogger $logger): void
    {
        self::$logger = $logger;
    }

    public function close(): void
    {
        if ($this->currentSchema !== null) {
            self::$logger?->logSchemaResetByConnectionClose($this->currentSchema);
        }

        parent::close();

        $this->currentSchema = null;
    }

    public function rollBack(): bool
    {
        if ($this->currentSchema !== null) {
            self::$logger?->logSchemaResetByTransactionRollback($this->currentSchema);
        }

        $result = parent::rollBack();

        $this->currentSchema = null;

        return $result;
    }

    public function connect(): bool
    {
        $isNewConnection = parent::connect();

        if (self::$schemaResolver === null) {
            return $isNewConnection;
        }

        if (self::$searchPathMode === SearchPathMode::Transaction) {
            if ($isNewConnection) {
                $this->ensurePostgreSql();
            }

            return $isNewConnection;
        }

        $schema = self::$schemaResolver->getSchema();

        if ($isNewConnection) {
            $this->currentSchema = null;
        }

        if ($this->currentSchema === $schema) {
            if (self::$logger !== null) {
                self::$logger->logActualSearchPath($this->getActualSearchPath());
                self::$logger->logSkipSearchPath($schema, $this->isTransactionActive());
            }

            return $isNewConnection;
        }
        $this->currentSchema = $schema;

        $this->ensurePostgreSql();
        $this->applySearchPath($schema, $isNewConnection);

        return $isNewConnection;
    }

    private static function isTransactionSearchPathActive(): bool
    {
        return self::$searchPathMode === SearchPathMode::Transaction && self::$schemaResolver !== null;
    }

    private static function resolveTransactionSearchPath(): ?string
    {
        if (self::$schemaResolver === null) {
            return null;
        }

        $schema = self::$schemaResolver->getSchema();

        if (self::$serverDefaultSchema !== null && $schema === self::$serverDefaultSchema) {
            return null;
        }

        return '"' . str_replace('"', '""', $schema) . '"';
    }

    private static function logTransactionSearchPath(string $quotedSchema, bool $isInTransaction): void
    {
        self::$logger?->logApplySearchPath($quotedSchema, false, $isInTransaction);
    }

    private function getActualSearchPath(): ?string
    {
        if ($this->_conn === null) {
            return null;
        }

        try {
            $result = $this->_conn->query('SHOW search_path');

            /** @var string $searchPath */
            $searchPath = $result->fetchFirstColumn()[0];

            return $searchPath;
        } catch (Throwable) {
            return null;
        }
    }

    private function ensurePostgreSql(): void
    {
        $platform = $this->getDatabasePlatform();

        if (!$platform instanceof PostgreSQLPlatform) {
            throw new UnsupportedPlatformException(get_class($platform));
        }
    }

    private function applySearchPath(string $schema, bool $isNewConnection): void
    {
        if ($this->_conn !== null) {
            $schema = $this->getDatabasePlatform()->quoteIdentifier($schema);

            self::$logger?->logApplySearchPath($schema, $isNewConnection, $this->isTransactionActive());

            $this->_conn->exec('SET search_path TO ' . $schema);
        }
    }
}
