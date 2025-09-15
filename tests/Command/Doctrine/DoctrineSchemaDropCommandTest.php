<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Tests\Command\Doctrine;

use Doctrine\DBAL\Connection;
use Macpaw\PostgresSchemaBundle\Command\Doctrine\DoctrineSchemaDropCommand;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class DoctrineSchemaDropCommandTest extends TestCase
{
    private Connection&MockObject $connection;
    private DoctrineSchemaDropCommand $command;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->command = new DoctrineSchemaDropCommand($this->connection);
    }

    public function testSuccess(): void
    {
        $input = new ArrayInput(['schema' => 'test_schema']);
        $output = new BufferedOutput();

        $this->connection->expects($this->once())
            ->method('quoteIdentifier')
            ->with('test_schema')
            ->willReturn('"test_schema"');

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with('DROP SCHEMA IF EXISTS "test_schema" CASCADE');

        $result = $this->command->run($input, $output);

        $this->assertEquals(Command::SUCCESS, $result);
    }
}
