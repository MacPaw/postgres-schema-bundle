<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Tests\Command\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Macpaw\PostgresSchemaBundle\Command\Doctrine\DoctrineSchemaMigrationsMigrateCommand;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

class DoctrineSchemaMigrationsMigrateCommandTest extends TestCase
{
    private Connection&MockObject $connection;
    private MigrateCommand&MockObject $parentCommand;
    private DoctrineSchemaMigrationsMigrateCommand $command;
    private Application&MockObject $application;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->parentCommand = $this->createMock(MigrateCommand::class);
        $this->parentCommand
            ->method('getDefinition')
            ->willReturn(new InputDefinition([
                new InputOption('no-interaction'),
            ]));

        $this->command = new DoctrineSchemaMigrationsMigrateCommand($this->parentCommand, $this->connection);
        $this->application = $this->createMock(Application::class);
        $this->command->setApplication($this->application);
    }

    public function testSuccess(): void
    {
        $input = new ArrayInput(['schema' => 'new_schema']);
        $output = new BufferedOutput();

        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = ?)', ['new_schema'])
            ->willReturn(0);

        $this->connection
            ->method('quoteIdentifier')
            ->with('new_schema')
            ->willReturn('"new_schema"');

        $this->connection->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function ($sql) {
                static $callCount = 0;
                $callCount++;
                if ($callCount === 1) {
                    $this->assertEquals('CREATE SCHEMA "new_schema"', $sql);
                } elseif ($callCount === 2) {
                    $this->assertEquals('SET search_path TO "new_schema"', $sql);
                }
            });

        $migrationsCommand = $this->createMock(Command::class);
        $this->application->expects($this->once())
            ->method('find')
            ->with('doctrine:migrations:migrate')
            ->willReturn($migrationsCommand);

        $migrationsCommand->expects($this->once())
            ->method('run')
            ->willReturn(Command::SUCCESS);

        $result = $this->command->run($input, $output);

        $this->assertEquals(Command::SUCCESS, $result);
    }
}
