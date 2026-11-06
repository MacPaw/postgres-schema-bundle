# Copilot review instructions

`macpaw/postgres-schema-bundle` is a public Symfony bundle. It switches the PostgreSQL `search_path` per request
through a Doctrine DBAL wrapper connection (`SchemaConnection`). Services configure it from `Kernel::boot()`.

## Compatibility

- PHP `>=8.3`, Doctrine DBAL `^3.4`, ORM `^2.17 || ^3.0`, Symfony `^6.4 || ^7.4 || ^8.0`.
- DBAL 3 driver interfaces declare return types in docblocks only. Native return types that narrow
  them in subclasses are fine; their absence is not a fatal error.

## Behaviour that must not change

- `SearchPathMode::Session` is the default. With it, every statement must pass through exactly as before
  the transaction mode existed. Flag any change that touches statements in Session mode.
- In `SearchPathMode::Transaction` the schema is applied only with `SET LOCAL`, never with a session `SET`.
  A session `SET` leaks to other clients behind PgBouncer in transaction pooling.
- Transaction control SQL (`BEGIN`, `COMMIT`, `ROLLBACK`, savepoints) must run without a `SET LOCAL` before it.

## Intentional decisions (do not flag)

- `BEGIN; SET LOCAL search_path TO ...` is sent in one `exec()` to save a round trip. Only `pdo_pgsql` is
  supported, and the native `pgsql` driver is rejected.
- Mode, resolver and logger are static setters, matching how the bundle has always been configured.
- Statements outside a transaction commit before the result is fetched; this relies on `pdo_pgsql` buffering.

## Code and tests

- `declare(strict_types=1)`, PSR-12, max line length 120, PHPStan level max.
- Unit tests in `tests/Doctrine` and `tests/Command` use mocks. Integration tests in `tests/Integration` need
  PostgreSQL behind PgBouncer in transaction pooling (`docker compose`, or the CI services) and must fail,
  not skip, in CI.
- Every change to `src/Doctrine/Middleware` needs an integration test that fails without it.
