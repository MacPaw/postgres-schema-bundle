<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Doctrine\Middleware;

use Closure;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Macpaw\PostgresSchemaBundle\Exception\UnsupportedDriverException;
use PDO;
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
        Driver $wrappedDriver,
        private readonly Closure $isActive,
        private readonly Closure $schemaProvider,
        private readonly Closure $onApply,
    ) {
        parent::__construct($wrappedDriver);
    }

    public function connect(#[SensitiveParameter] array $params): Driver\Connection
    {
        $connection = parent::connect($params);

        // Only pdo_pgsql defers statement preparation to execution, inside SET LOCAL. The check looks at the
        // native connection, so it also works when other driver middlewares wrap the driver.
        if (($this->isActive)()) {
            $native = method_exists($connection, 'getNativeConnection') ? $connection->getNativeConnection() : null;

            if (!$native instanceof PDO) {
                throw new UnsupportedDriverException(get_debug_type($native));
            }
        }

        return new TransactionSearchPathConnection(
            $connection,
            $this->isActive,
            $this->schemaProvider,
            $this->onApply,
        );
    }
}
