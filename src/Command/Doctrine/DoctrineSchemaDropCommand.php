<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Command\Doctrine;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DoctrineSchemaDropCommand extends AbstractDoctrineSchemaCommand
{
    public function __construct(Connection $connection)
    {
        parent::__construct('doctrine:schema:delete', $connection);
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $schema = $this->getSchemaFromInput($input);

        $output->writeln("<info>Drop schema '{$schema}'...<info>");

        $quotedSchema = $this->connection->quoteIdentifier($schema);
        $this->connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', $quotedSchema));

        return Command::SUCCESS;
    }
}
