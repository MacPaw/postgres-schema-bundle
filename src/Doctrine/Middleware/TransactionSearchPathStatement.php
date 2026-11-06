<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

final class TransactionSearchPathStatement extends AbstractStatementMiddleware
{
    public function __construct(
        Statement $wrappedStatement,
        private readonly TransactionSearchPathConnection $connection,
    ) {
        parent::__construct($wrappedStatement);
    }

    public function execute($params = null): Result
    {
        return $this->connection->runWithSearchPath(fn (): Result => parent::execute($params));
    }
}
