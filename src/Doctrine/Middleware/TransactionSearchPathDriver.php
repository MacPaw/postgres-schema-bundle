<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Doctrine\Middleware;

use Closure;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\PgSQL\Driver as NativePgSQLDriver;
use Macpaw\PostgresSchemaBundle\Exception\UnsupportedDriverException;
use SensitiveParameter;

final class TransactionSearchPathDriver extends AbstractDriverMiddleware
{
    /**
     * @param Closure(): bool             $isActive       whether transaction search_path mode is on
     * @param Closure(): ?string          $schemaProvider returns the quoted schema to apply,
     *                                                    or null to keep the server default
     * @param Closure(string, bool): void $onApply        called with the quoted schema and whether
     *                                                    the transaction was opened by the caller
     */
    public function __construct(
        private readonly Driver $wrappedDriver,
        private readonly Closure $isActive,
        private readonly Closure $schemaProvider,
        private readonly Closure $onApply,
    ) {
        parent::__construct($wrappedDriver);
    }

    public function connect(#[SensitiveParameter] array $params): Driver\Connection
    {
        // The native pgsql driver prepares statements immediately, before SET LOCAL is applied.
        if (($this->isActive)() && $this->wrappedDriver instanceof NativePgSQLDriver) {
            throw new UnsupportedDriverException('pgsql');
        }

        return new TransactionSearchPathConnection(
            parent::connect($params),
            $this->isActive,
            $this->schemaProvider,
            $this->onApply,
        );
    }
}
