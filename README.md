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
    Macpaw\SchemaContextBundle\PostgresSchemaBundle::class => ['all' => true],
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

Set `SchemaResolver` to `SchemaConnection` at kernel boot
```php
# src/Kernel.php

use Macpaw\PostgresSchemaBundle\Doctrine\SchemaConnection;
use Macpaw\SchemaContextBundle\Service\SchemaResolver;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        parent::boot();
    
        SchemaConnection::setSchemaResolver(
            $this->getContainer()->get(SchemaResolver::class),
        );
    }

    // ...
}
```

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
* When Doctrine connects to PostgreSQL, it sets the search_path to the specified schema.
* If the schema does not exist or DB is not PostgreSQL, an exception is thrown.

## Testing 
To run tests:
```bash
vendor/bin/phpunit
```
## Contributing
Feel free to open issues and submit pull requests.

## License
This bundle is released under the MIT license.
