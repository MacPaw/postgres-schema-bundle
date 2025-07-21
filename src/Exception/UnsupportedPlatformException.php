<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Exception;

use RuntimeException;

class UnsupportedPlatformException extends RuntimeException
{
    public function __construct(string $platform)
    {
        parent::__construct("Unsupported database platform: {$platform}. PostgreSQL is required.");
    }
}
