<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Doctrine;

use Doctrine\DBAL\Connection as DBALConnection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Macpaw\PostgresSchemaBundle\Exception\UnsupportedPlatformException;
use Macpaw\SchemaContextBundle\Service\BaggageSchemaResolver;

class SchemaConnection extends DBALConnection
{
    private static ?BaggageSchemaResolver $schemaResolver = null;

    public static function setSchemaResolver(BaggageSchemaResolver $resolver): void
    {
        self::$schemaResolver = $resolver;
    }

    public function connect(): bool
    {
        $connection = parent::connect();

        if (self::$schemaResolver === null) {
            return $connection;
        }

        $schema = self::$schemaResolver->getSchema();

        if (!$schema) {
            return $connection;
        }

        $this->ensurePostgreSql();
        $this->applySearchPath($schema);

        return $connection;
    }

    private function ensurePostgreSql(): void
    {
        $platform = $this->getDatabasePlatform();

        if (!$platform instanceof PostgreSQLPlatform) {
            throw new UnsupportedPlatformException(get_class($platform));
        }
    }

    private function applySearchPath(string $schema): void
    {
        if ($this->_conn !== null) {
            $this->_conn->exec('SET search_path TO ' . $schema);
        }
    }
}
