<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Tests\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Macpaw\PostgresSchemaBundle\Doctrine\SchemaConnection;
use Macpaw\PostgresSchemaBundle\Doctrine\SearchPathMode;
use Macpaw\PostgresSchemaBundle\Exception\UnsupportedDriverException;
use Macpaw\PostgresSchemaBundle\Exception\UnsupportedPlatformException;
use Macpaw\SchemaContextBundle\Logger\DebugLogger;
use Macpaw\SchemaContextBundle\Service\BaggageSchemaResolver;
use PDO;
use PHPUnit\Framework\TestCase;
use stdClass;

class SchemaConnectionTest extends TestCase
{
    public function testConnectSetsSearchPath(): void
    {
        $driverConnection = $this->createMock(DriverConnection::class);

        $driverConnection->expects($this->once())
            ->method('exec')
            ->with('SET search_path TO "test_schema"');

        $driver = $this->createMock(Driver::class);

        $driver->method('connect')->willReturn($driverConnection);

        $platform = new PostgreSQLPlatform();
        $connection = $this->getMockBuilder(SchemaConnection::class)
            ->setConstructorArgs([[], $driver, new Configuration(), new EventManager()])
            ->onlyMethods(['getDatabasePlatform', 'fetchOne'])
            ->getMock();

        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('fetchOne')->willReturn(true);

        $logger = new DebugLogger();
        $resolver = new BaggageSchemaResolver(
            'public',
            'development',
            ['development'],
            $logger,
        );

        $resolver->setSchema('test_schema');

        SchemaConnection::setSchemaResolver($resolver);

        $result = $connection->connect();

        self::assertTrue($result);
    }

    public function testConnectSetsSearchPathWithSpecialChars(): void
    {
        $driverConnection = $this->createMock(DriverConnection::class);

        $driverConnection->expects($this->once())
            ->method('exec')
            ->with('SET search_path TO "test-schema/foo"');

        $driver = $this->createMock(Driver::class);

        $driver->method('connect')->willReturn($driverConnection);

        $platform = new PostgreSQLPlatform();
        $connection = $this->getMockBuilder(SchemaConnection::class)
            ->setConstructorArgs([[], $driver, new Configuration(), new EventManager()])
            ->onlyMethods(['getDatabasePlatform', 'fetchOne'])
            ->getMock();

        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('fetchOne')->willReturn(true);

        $logger = new DebugLogger();
        $resolver = new BaggageSchemaResolver(
            'public',
            'development',
            ['development'],
            $logger,
        );

        $resolver->setSchema('test-schema/foo');

        SchemaConnection::setSchemaResolver($resolver);

        $result = $connection->connect();

        self::assertTrue($result);
    }

    public function testConnectSkipsWhenNoSchema(): void
    {
        $driverConnection = $this->createMock(DriverConnection::class);

        $driverConnection->expects($this->once())
            ->method('exec')
            ->with('SET search_path TO "public"');

        $driver = $this->createMock(Driver::class);

        $driver->method('connect')->willReturn($driverConnection);

        $platform = new PostgreSQLPlatform();
        $connection = $this->getMockBuilder(SchemaConnection::class)
            ->setConstructorArgs([[], $driver, new Configuration(), new EventManager()])
            ->onlyMethods(['getDatabasePlatform', 'fetchOne'])
            ->getMock();

        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('fetchOne')->willReturn(true);

        $logger = new DebugLogger();
        $resolver = new BaggageSchemaResolver(
            'public',
            'development',
            ['development'],
            $logger,
        );

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

        $logger = new DebugLogger();
        $resolver = new BaggageSchemaResolver(
            'public',
            'development',
            ['development'],
            $logger,
        );

        $resolver->setSchema('test_schema');

        SchemaConnection::setSchemaResolver($resolver);

        $connection->connect();
    }

    public function testTransactionModeThrowsForUnsupportedPlatform(): void
    {
        SchemaConnection::setSearchPathMode(SearchPathMode::Transaction);
        $driverConnection = $this->createMock(AbstractConnectionMiddleware::class);
        $driverConnection->method('getNativeConnection')->willReturn($this->createMock(PDO::class));
        $driverConnection->expects($this->never())->method('exec');
        $driver = $this->createMock(Driver::class);
        $driver->method('connect')->willReturn($driverConnection);

        $connection = $this->getMockBuilder(SchemaConnection::class)
            ->setConstructorArgs([[], $driver, new Configuration(), new EventManager()])
            ->onlyMethods(['getDatabasePlatform'])
            ->getMock();
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());

        SchemaConnection::setSchemaResolver(
            new BaggageSchemaResolver('public', 'development', ['development'], new DebugLogger()),
        );

        try {
            $this->expectException(UnsupportedPlatformException::class);
            $connection->connect();
        } finally {
            SchemaConnection::setSearchPathMode(SearchPathMode::Session);
        }
    }

    public function testTransactionModeRejectsNonPdoConnection(): void
    {
        SchemaConnection::setSearchPathMode(SearchPathMode::Transaction);
        $driverConnection = $this->createMock(AbstractConnectionMiddleware::class);
        $driverConnection->method('getNativeConnection')->willReturn(new stdClass());
        $driverConnection->expects($this->never())->method('exec');
        $driver = $this->createMock(Driver::class);
        $driver->method('connect')->willReturn($driverConnection);
        $connection = new SchemaConnection([], $driver, new Configuration(), new EventManager());

        SchemaConnection::setSchemaResolver(
            new BaggageSchemaResolver('public', 'development', ['development'], new DebugLogger()),
        );

        try {
            $this->expectException(UnsupportedDriverException::class);
            $connection->connect();
        } finally {
            SchemaConnection::setSearchPathMode(SearchPathMode::Session);
        }
    }

    public function testSessionModeDoesNotWrapDriverConnection(): void
    {
        $driverConnection = $this->createMock(DriverConnection::class);
        $driver = $this->createMock(Driver::class);
        $driver->method('connect')->willReturn($driverConnection);
        $driver->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        $connection = new SchemaConnection([], $driver, new Configuration(), new EventManager());

        self::assertSame($driverConnection, $connection->getWrappedConnection());
    }
}
