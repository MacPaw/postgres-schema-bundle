<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Command\Doctrine;

use Doctrine\DBAL\Connection;
use Error;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractNestingDoctrineSchemaCommand extends AbstractDoctrineSchemaCommand
{
    public function __construct(
        string $commandName,
        private readonly Command $parentCommand,
        Connection $connection,
    ) {
        parent::__construct($commandName, $connection);
    }

    protected function configure(): void
    {
        parent::configure();

        foreach ($this->parentCommand->getDefinition()->getArguments() as $argument) {
            $this->addArgument(
                $argument->getName(),
                $argument->isRequired() ? InputArgument::REQUIRED : InputArgument::OPTIONAL,
                $argument->getDescription(),
                $argument->getDefault(),
            );
        }

        foreach ($this->parentCommand->getDefinition()->getOptions() as $option) {
            $this->addOption(
                $option->getName(),
                $option->getShortcut(),
                $option->isValueRequired() ? InputOption::VALUE_REQUIRED : InputOption::VALUE_OPTIONAL,
                $option->getDescription(),
                $option->getDefault(),
            );
        }
    }

    protected function runCommand(string $commandName, InputInterface $input, OutputInterface $output): int
    {
        $application = $this->getApplication();

        if ($application === null) {
            throw new Error('Application is not available');
        }

        $command = $application->find($commandName);

        $arguments = [];
        foreach ($input->getArguments() as $name => $value) {
            if ($value === null) {
                continue;
            }

            if ($name === 'schema' || $name === 'command') {
                continue;
            }

            if ($this->getDefinition()->getArguments()[$name]->getDefault() === $value) {
                continue;
            }

            $arguments[$name] = $value;
        }

        $options = [];
        foreach ($input->getOptions() as $name => $value) {
            if ($value === null) {
                continue;
            }

            if ($this->getDefinition()->getOptions()[$name]->getDefault() === $value) {
                continue;
            }

            $options['--' . $name] = $value;
        }

        $commandInput = new ArrayInput([
            ...$arguments,
            ...$options,
        ]);

        if ($input->getOption('no-interaction') === true) {
            $commandInput->setInteractive(false);
        }

        return $command->run($commandInput, $output);
    }
}
