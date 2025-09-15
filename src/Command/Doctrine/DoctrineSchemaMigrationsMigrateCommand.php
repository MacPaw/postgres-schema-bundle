<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Command\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class DoctrineSchemaMigrationsMigrateCommand extends AbstractNestingDoctrineSchemaCommand
{
    public function __construct(
        MigrateCommand $parentCommand,
        Connection $connection,
    ) {
        parent::__construct('doctrine:schema:migrations:migrate', $parentCommand, $connection);
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        try {
            $schema = $this->getSchemaFromInput($input);

            if ($this->isSchemaExist($schema)) {
                $output->writeln("<info>Schema '{$schema}' already exists.</info>");
            } else {
                $output->writeln("<info>Creating schema '{$schema}'...</info>");
                $this->connection->executeStatement('CREATE SCHEMA ' . $this->connection->quoteIdentifier($schema));
            }

            $this->switchToSchema($schema);

            $output->writeln("<info>Running migrations for '{$schema}'...</info>");

            $returnCode = $this->runCommand('doctrine:migrations:migrate', $input, $output);

            if ($returnCode !== Command::SUCCESS) {
                $output->writeln("<error>Migrations failed with return code: {$returnCode}</error>");

                return Command::FAILURE;
            }
        } catch (Throwable $e) {
            $output->writeln("<error>Error executing migrations: {$e->getMessage()}</error>");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
