<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Tests\Command\Doctrine;

use Doctrine\Bundle\FixturesBundle\Command\LoadDataFixturesDoctrineCommand;
use Doctrine\DBAL\Connection;
use Macpaw\PostgresSchemaBundle\Command\Doctrine\DoctrineSchemaFixturesLoadCommand;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

class DoctrineSchemaFixturesLoadCommandTest extends TestCase
{
    private Connection&MockObject $connection;
    private Application&MockObject $application;
    private LoadDataFixturesDoctrineCommand&Command&MockObject $parentCommand;
    private DoctrineSchemaFixturesLoadCommand $command;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->application = $this->createMock(Application::class);
        $this->parentCommand = $this->createMock(LoadDataFixturesDoctrineCommand::class);
        $this->parentCommand
            ->method('getDefinition')
            ->willReturn(new InputDefinition([
                new InputOption('no-interaction'),
            ]));

        $this->command = new DoctrineSchemaFixturesLoadCommand($this->parentCommand, $this->connection, ['public']);
        $this->command->setApplication($this->application);
    }

    public function testSuccess(): void
    {
        $input = new ArrayInput(['schema' => 'test_schema']);
        $output = new BufferedOutput();

        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = ?)', ['test_schema'])
            ->willReturn(1);

        $this->connection->expects($this->once())
            ->method('quoteIdentifier')
            ->with('test_schema')
            ->willReturn('"test_schema"');

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with('SET search_path TO "test_schema"');

        $fixturesCommand = $this->createMock(Command::class);
        $this->application->expects($this->once())
            ->method('find')
            ->with('doctrine:fixtures:load')
            ->willReturn($fixturesCommand);

        $fixturesCommand->expects($this->once())
            ->method('run')
            ->willReturn(Command::SUCCESS);

        $result = $this->command->run($input, $output);

        $this->assertEquals(Command::SUCCESS, $result);
        $this->assertStringContainsString("Load fixtures for 'test_schema'...", $output->fetch());
    }

    public function testDisallowedSchemaNameFail(): void
    {
        $input = new ArrayInput(['schema' => 'public']);
        $output = new BufferedOutput();

        $result = $this->command->run($input, $output);

        $this->assertStringContainsString(
            "Command is disallowed from being called for the 'public' schema",
            $output->fetch()
        );
        $this->assertEquals(Command::FAILURE, $result);
    }
}
