<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Command\Doctrine;

use Doctrine\Bundle\FixturesBundle\Command\LoadDataFixturesDoctrineCommand;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class DoctrineSchemaFixturesLoadCommand extends AbstractNestingDoctrineSchemaCommand
{
    public function __construct(
        LoadDataFixturesDoctrineCommand $parentCommand,
        Connection $connection,
    ) {
        parent::__construct('doctrine:schema:fixtures:load', $parentCommand, $connection);
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        try {
            $schema = $this->getSchemaFromInput($input);

            if (!$this->isSchemaExist($schema)) {
                $output->writeln("<error>Schema '{$schema}' doesn't exist</error>");

                return Command::FAILURE;
            }

            $this->switchToSchema($schema);

            $output->writeln("<info>Load fixtures for '{$schema}'...</info>");

            $returnCode = $this->runCommand('doctrine:fixtures:load', $input, $output);

            if ($returnCode !== Command::SUCCESS) {
                $output->writeln("<error>Fixtures load failed with return code: {$returnCode}</error>");

                return Command::FAILURE;
            }
        } catch (Throwable $e) {
            $output->writeln("<error>Error executing fixtures load: {$e->getMessage()}</error>");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
