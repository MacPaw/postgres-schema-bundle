<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Exception;

use RuntimeException;

class UnsupportedDriverException extends RuntimeException
{
    public function __construct(string $driver)
    {
        parent::__construct(
            "Unsupported database driver: {$driver}. Transaction search_path mode requires pdo_pgsql.",
        );
    }
}
