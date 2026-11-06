<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Exception;

use LogicException;

class TransactionSearchPathModeException extends LogicException
{
    public static function rawTransactionControl(): self
    {
        return new self(
            'Transaction search_path mode does not support raw BEGIN, COMMIT or ROLLBACK SQL. '
            . 'Use the DBAL transaction API: beginTransaction(), commit(), rollBack() or transactional().',
        );
    }

    public static function modeChangedInTransaction(): self
    {
        return new self(
            'The search_path mode changed while a transaction is open. '
            . 'Call SchemaConnection::setSearchPathMode() in Kernel::boot(), before any transaction starts.',
        );
    }
}
