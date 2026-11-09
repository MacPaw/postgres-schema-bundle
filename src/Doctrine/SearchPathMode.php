<?php

declare(strict_types=1);

namespace Macpaw\PostgresSchemaBundle\Doctrine;

enum SearchPathMode: string
{
    /** Session-level SET search_path once per connection. Requires session pooling or a direct connection. */
    case Session = 'session';

    /** SET LOCAL search_path inside every transaction. Safe behind PgBouncer transaction pooling. */
    case Transaction = 'transaction';
}
