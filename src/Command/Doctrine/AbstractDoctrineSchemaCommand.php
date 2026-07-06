<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Command\Doctrine;

use Doctrine\DBAL\Connection;
use Error;
use Macpaw\SchemaContextBundle\Service\BaggageSchemaResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

abstract class AbstractDoctrineSchemaCommand extends Command
{
    public function __construct(
        string $commandName,
        protected readonly Connection $connection,
        protected readonly BaggageSchemaResolver $schemaResolver,
    ) {
        parent::__construct($commandName);
    }

    protected function configure(): void
    {
        $this->addArgument(
            'schema',
            InputArgument::REQUIRED,
            'The schema name.',
        );

        parent::configure();
    }

    protected function getSchemaFromInput(InputInterface $input): string
    {
        $schema = $input->getArgument('schema');

        if (!is_string($schema) || $schema === '') {
            throw new Error('Schema name must be a non-empty string');
        }

        return $schema;
    }

    protected function isSchemaExist(string $schema): bool
    {
        $exists = $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = ?)',
            [$schema],
        );

        return (bool) $exists;
    }

    protected function switchToSchema(string $schema): void
    {
        $this->schemaResolver->setSchema($schema);
        $quotedSchema = $this->connection->quoteIdentifier($schema);

        $this->connection->executeStatement("SET search_path TO {$quotedSchema}");
    }
}
