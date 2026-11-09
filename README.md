# Postgres Schema Bundle
The **Postgres Schema Bundle** provides seamless multi-tenant schema support for PostgreSQL within Symfony applications. It automatically switches PostgreSQL `search_path` based on the current request context and ensures proper schema resolution across Doctrine and Messenger.## Installation

## Features

- Automatically sets PostgreSQL `search_path` from request headers.
- Validates that the schema exists in the database.
- Works only if the configured database driver is PostgreSQL.
- Integrates with [Schema Context Bundle](https://github.com/macpaw/schema-context-bundle).
- Compatible with Symfony Messenger and Doctrine ORM.

## Installation
Use Composer to install the bundle:
```
composer require macpaw/postgres-schema-bundle
```

### Applications that don't use Symfony Flex
Enable the bundle by adding it to the list of registered bundles in ```config/bundles.php```

```
// config/bundles.php
<?php

return [
    // ...
    Macpaw\SchemaContextBundle\SchemaContextBundle::class => ['all' => true],
    Macpaw\PostgresSchemaBundle\PostgresSchemaBundle::class => ['all' => true],
];
```

## Configuration

You must tell Doctrine to use the SchemaConnection class as its DBAL connection class:

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        connections:
            default:
                wrapper_class: Macpaw\PostgresSchemaBundle\Doctrine\SchemaConnection
```

Set `BaggageSchemaResolver` to `SchemaConnection` at kernel boot
```php
# src/Kernel.php

use Macpaw\PostgresSchemaBundle\Doctrine\SchemaConnection;
use Macpaw\SchemaContextBundle\Service\BaggageSchemaResolver;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        parent::boot();

        SchemaConnection::setSchemaResolver(
            $this->getContainer()->get(BaggageSchemaResolver::class),
        );
    }

    // ...
}
```

### Search path mode (PgBouncer transaction pooling)

By default the bundle sets `search_path` once per database session (`SET search_path TO ...`).
This is safe only with a direct connection or PgBouncer in **session** pooling mode.
Behind PgBouncer in **transaction** pooling mode a session setting stays on the pooled server connection
and leaks to other clients: their queries run in another schema.

Switch to the transaction mode at kernel boot, next to `setSchemaResolver()`:

```php
use Macpaw\PostgresSchemaBundle\Doctrine\SchemaConnection;
use Macpaw\PostgresSchemaBundle\Doctrine\SearchPathMode;

SchemaConnection::setSearchPathMode(SearchPathMode::Transaction);
```

`SchemaConnection::setSearchPathMode(SearchPathMode $mode, ?string $serverDefaultSchema = null)`:

| Argument | Value | Behaviour |
|---|---|---|
| `$mode` | `SearchPathMode::Session` (default) | `SET search_path` once per connection; current behaviour |
| | `SearchPathMode::Transaction` | `SET LOCAL search_path` inside every transaction; the setting never outlives the transaction |
| `$serverDefaultSchema` | `null` (strict, default) | `SET LOCAL` for every schema, including the default one |
| | `'public'` or another schema | queries for this schema run without `SET LOCAL` and rely on the server default `search_path` |

In transaction mode:

- A statement outside a transaction runs as `BEGIN; SET LOCAL search_path TO "<schema>"`, the statement, then `COMMIT`
  (`ROLLBACK` on error): two extra round trips.
- Inside a transaction `SET LOCAL` runs once after `BEGIN`, and again only when the schema changes or after
  `ROLLBACK TO SAVEPOINT`.
- Plain queries, executed statements and prepared statements (Doctrine ORM) are all covered.
- The schema commands below only set the schema in the resolver; they do not issue a session `SET`.
- Manage transactions through the DBAL API: `beginTransaction()`, `commit()`, `rollBack()`, `transactional()`.
  Raw `BEGIN`, `START TRANSACTION`, `COMMIT`, `ROLLBACK`, `END`, `ABORT` or `PREPARE TRANSACTION` SQL is rejected
  with `TransactionSearchPathModeException`, as is any of it in a prepared statement or batched with other
  statements. A single savepoint statement (`SAVEPOINT`, `RELEASE`, `ROLLBACK TO`), which DBAL uses for nested
  transactions, runs as is.
- The SQL check fails closed. SQL with `;` or a leading comment that cannot be split reliably (unbalanced quotes,
  comments or dollar quotes, or a PCRE limit error) is rejected; send such statements one by one. After every
  statement the server transaction status (`PDO::inTransaction()`) is compared with the expected one: if transaction
  control slipped past the check, the connection rolls back a transaction it left open and throws, and refuses
  further statements in a DBAL transaction that the server no longer has.
- The mode is applied when a physical connection opens. An idle connection opened in the other mode is reopened;
  changing the mode while a transaction is open throws `TransactionSearchPathModeException`. Set the mode in
  `Kernel::boot()` before running queries: statements prepared before the switch keep the old mode.
- In the default `Session` mode the driver connection is not wrapped, and the bundle behaves exactly as before.

Choosing `$serverDefaultSchema`:

- Use `null` while any client of the same database still sets a session `search_path`: pods on an older bundle
  version during rollout, session-mode services sharing the pool, manual `psql` sessions.
  Otherwise default-schema queries inherit the leaked setting.
- Pass the schema only when every client of the database uses transaction mode and the server default
  `search_path` (role or database setting) resolves to that schema.
  For an environment whose default schema is not `public`, set it first with
  `ALTER ROLE ... SET search_path` or `ALTER DATABASE ... SET search_path`.

Limitations:

- Only the `pdo_pgsql` driver is supported. It prepares a statement when it is executed, inside `SET LOCAL`.
  The native `pgsql` driver prepares immediately, against the server default `search_path`, so it is rejected
  with `UnsupportedDriverException`.
- `pdo_pgsql` uses server-side prepared statements, and a statement reused outside a transaction can run on
  another server connection. PgBouncer must track prepared statements: version 1.21+ with
  `max_prepared_statements` greater than 0 (the default since 1.22).
- A statement outside a transaction is committed before its result is fetched. This relies on `pdo_pgsql`
  buffering the whole result (the default); do not enable lazy (unbuffered) result fetching.
- Statements that cannot run inside a transaction block (`CREATE INDEX CONCURRENTLY`, `VACUUM`, migrations with
  `isTransactional() = false`) fail for a schema that needs `SET LOCAL`.
- `lastInsertId()` outside a transaction runs after the implicit `COMMIT`, possibly on another server connection
  and without the schema `search_path`: `LASTVAL()` and `CURRVAL('<sequence>')` can fail or return another value.
  Fetch ids with `nextval()` (Doctrine ORM `SEQUENCE` strategy), `INSERT ... RETURNING`, or call `lastInsertId()`
  inside the same explicit transaction as the insert.

Make sure you configure the context bundle properly:

See https://github.com/MacPaw/schema-context-bundle/blob/develop/README.md

```yaml
schema_context:
    app_name: '%env(APP_NAME)%' # Application name
    header_name: 'X-Tenant' # Request header to extract schema name
    default_schema: 'public' # Default schema to fallback to
    allowed_app_names: ['develop', 'staging', 'test'] # App names where schema context is allowed to change
```

## How it Works
* A request comes in with a header like X-Tenant-Id: tenant123.
* The SchemaRequestListener sets this schema in the context.
* When Doctrine connects to PostgreSQL, it sets the search_path to the specified schema
  (per session, or per transaction in `SearchPathMode::Transaction`).
* If the schema does not exist or DB is not PostgreSQL, an exception is thrown.

## Optional Commands

The bundle provides three optional commands for schema management that can be registered in your services configuration:

### Schema Drop Command
Drops a PostgreSQL schema and all its objects:

```yaml
# config/services.yaml
services:
    SharedServices\Command\Doctrine\DoctrineSchemaDropCommand:
        arguments:
            - '@Doctrine\DBAL\Connection'
            - ['public'] # Disallowed schema names for safety
```

Usage:
```bash
php bin/console doctrine:database:schema:drop <schema_name>
```

**Security Note:** You can specify disallowed schema names to prevent accidental deletion of critical schemas like `public`.

### Schema Migrations Command
Runs Doctrine migrations within a specific schema. Creates the schema if it doesn't exist:

```yaml
# config/services.yaml
services:
    SharedServices\Command\Doctrine\DoctrineSchemaMigrationsMigrateCommand:
        arguments:
            - '@doctrine_migrations.migrate_command'
```

Usage:
```bash
php bin/console doctrine:schema:migrations:migrate <schema_name> [options]
```

### Schema Fixtures Load Command
Loads Doctrine fixtures within a specific schema:

```yaml
# config/services.yaml
services:
    SharedServices\Command\Doctrine\DoctrineSchemaFixturesLoadCommand:
        arguments:
            - '@doctrine.fixtures_load_command'
            - '@Doctrine\DBAL\Connection'
            - ['public'] # Disallowed schema names for safety
```

Usage:
```bash
php bin/console doctrine:schema:fixtures:load <schema_name> [options]
```

**Security Note:** You can specify disallowed schema names to prevent accidental fixture loading into critical schemas like `public`.

**Note:** These commands are optional and should only be registered if you're using the corresponding Doctrine features (migrations and/or fixtures) in your project.

## Testing
Unit tests:
```bash
vendor/bin/phpunit --testsuite unit
```

Integration tests run against PostgreSQL (14 by default, `POSTGRES_VERSION=18` for 18) behind PgBouncer in transaction pooling mode
with a single server connection, so every client shares one server session:
```bash
docker compose up -d --build
docker compose run --rm php composer install
docker compose run --rm php vendor/bin/phpunit
docker compose down
```

Without `DIRECT_DATABASE_URL` and `PGBOUNCER_DATABASE_URL` the integration tests are skipped.
CI runs them in every matrix job against PostgreSQL 14, and in one job against PostgreSQL 18.
## Contributing
Feel free to open issues and submit pull requests.

## License
This bundle is released under the MIT license.
