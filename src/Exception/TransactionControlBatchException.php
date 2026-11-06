<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Exception;

use RuntimeException;

class TransactionControlBatchException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Transaction search_path mode cannot run transaction control SQL batched with other statements. '
            . 'Send BEGIN, COMMIT, ROLLBACK and savepoints as separate statements.',
        );
    }
}
