<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Doctrine;

use Doctrine\DBAL\Connection as DBALConnection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Macpaw\PostgresSchemaBundle\Exception\UnsupportedPlatformException;
use Macpaw\SchemaContextBundle\Logger\DebugLogger;
use Macpaw\SchemaContextBundle\Service\BaggageSchemaResolver;

class SchemaConnection extends DBALConnection
{
    private static ?BaggageSchemaResolver $schemaResolver = null;
    private static ?DebugLogger $logger = null;
    private ?string $currentSchema = null;

    public static function setSchemaResolver(BaggageSchemaResolver $resolver): void
    {
        self::$schemaResolver = $resolver;
    }

    public static function setLogger(DebugLogger $logger): void
    {
        self::$logger = $logger;
    }

    public function connect(): bool
    {
        $isNewConnection = parent::connect();

        if (self::$schemaResolver === null) {
            return $isNewConnection;
        }

        $schema = self::$schemaResolver->getSchema();

        if ($isNewConnection) {
            $this->currentSchema = null;
        }

        if ($this->currentSchema === $schema) {
            if (self::$logger !== null) {
                $actualScheme = $this->getActualSearchPath();
                self::$logger->logSkipSearchPath($schema, $actualScheme);
            }

            return $isNewConnection;
        }
        $this->currentSchema = $schema;

        $this->ensurePostgreSql();
        $this->applySearchPath($schema, $isNewConnection);

        return $isNewConnection;
    }

    private function getActualSearchPath(): ?string
    {
        if ($this->_conn !== null) {
            $result = $this->_conn->query('SHOW search_path');

            return $result->fetchFirstColumn()[0];
        }

        return null;
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

            self::$logger?->logApplySearchPath($schema, $isNewConnection);

            $this->_conn->exec('SET search_path TO ' . $schema);
        }
    }
}
