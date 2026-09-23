<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Command\Doctrine;

use Doctrine\DBAL\Connection;
use Macpaw\SchemaContextBundle\Service\BaggageSchemaResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DoctrineSchemaDropCommand extends AbstractDoctrineSchemaCommand
{
    /**
     * @param string[] $disallowedSchemaNames
     */
    public function __construct(
        Connection $connection,
        BaggageSchemaResolver $schemaResolver,
        private readonly array $disallowedSchemaNames = [],
    ) {
        parent::__construct('doctrine:database:schema:drop', $connection, $schemaResolver);
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $schema = $this->getSchemaFromInput($input);

        if (in_array($schema, $this->disallowedSchemaNames, true)) {
            $output->writeln(
                "<error>Command is disallowed from being called for the '$schema' schema</error>"
            );

            return Command::FAILURE;
        }

        $output->writeln("<info>Drop schema '{$schema}'...<info>");

        $quotedSchema = $this->connection->quoteIdentifier($schema);
        $this->connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', $quotedSchema));

        return Command::SUCCESS;
    }
}
