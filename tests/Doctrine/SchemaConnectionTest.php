<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Tests\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Macpaw\PostgresSchemaBundle\Doctrine\SchemaConnection;
use Macpaw\PostgresSchemaBundle\Exception\UnsupportedPlatformException;
use Macpaw\SchemaContextBundle\Service\BaggageSchemaResolver;
use PHPUnit\Framework\TestCase;

class SchemaConnectionTest extends TestCase
{
    public function testConnectSetsSearchPath(): void
    {
        $driverConnection = $this->createMock(DriverConnection::class);

        $driverConnection->expects($this->once())
            ->method('exec')
            ->with('SET search_path TO test_schema');

        $driver = $this->createMock(Driver::class);

        $driver->method('connect')->willReturn($driverConnection);

        $platform = new PostgreSQLPlatform();
        $connection = $this->getMockBuilder(SchemaConnection::class)
            ->setConstructorArgs([[], $driver, new Configuration(), new EventManager()])
            ->onlyMethods(['getDatabasePlatform', 'fetchOne'])
            ->getMock();

        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('fetchOne')->willReturn(true);

        $resolver = new BaggageSchemaResolver();

        $resolver->setSchema('test_schema');

        SchemaConnection::setSchemaResolver($resolver);

        $result = $connection->connect();

        self::assertTrue($result);
    }

    public function testConnectSkipsWhenNoSchema(): void
    {
        $driverConnection = $this->createMock(DriverConnection::class);

        $driver = $this->createMock(Driver::class);

        $driver->method('connect')->willReturn($driverConnection);

        $connection = new SchemaConnection([], $driver, new Configuration());
        $resolver = new BaggageSchemaResolver();

        SchemaConnection::setSchemaResolver($resolver);

        self::assertTrue($connection->connect());
    }

    public function testThrowsForUnsupportedPlatform(): void
    {
        $this->expectException(UnsupportedPlatformException::class);

        $driverConnection = $this->createMock(DriverConnection::class);
        $driver = $this->createMock(Driver::class);

        $driver->method('connect')->willReturn($driverConnection);

        $platform = new MySQLPlatform();
        $connection = $this->getMockBuilder(SchemaConnection::class)
            ->setConstructorArgs([[], $driver, new Configuration(), new EventManager()])
            ->onlyMethods(['getDatabasePlatform'])
            ->getMock();

        $connection->method('getDatabasePlatform')->willReturn($platform);

        $resolver = new BaggageSchemaResolver();

        $resolver->setSchema('test_schema');

        SchemaConnection::setSchemaResolver($resolver);

        $connection->connect();
    }
}
