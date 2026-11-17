# PostgreSQL Migration Package
A package to make migrations for PostgreSQL easier in PHP.

## Usage
Put each migration in its own file in one folder, named with its version first, e.g. `001_CreateUserTable.php`, each
holding one class that implements `MigrationInterface`. Then migrate to the latest version:

```php
$manager = new \Programster\PgsqlMigrations\MigrationManager(__DIR__ . '/migrations', $connection);
$manager->migrate();
```

The database's version is kept in a `migrations` table in the `public` schema, which is created if it doesn't exist.

## Migrations in a package
A package that ships its own migrations gives its manager a version table of its own, and optionally a schema, which
are created if they don't exist. Its version is then kept separately from the application's, so the two never clash:

```php
$manager = new MigrationManager(__DIR__ . '/migrations', $connection, 'task_queue_migrations', 'task_queue');
```

## Testing
The tests need a throwaway PostgreSQL database, as they drop and re-create its `public` schema:

```bash
composer install
TEST_DB_HOST=localhost TEST_DB_NAME=test TEST_DB_USER=test TEST_DB_PASSWORD=test php -d zend.assertions=1 testing/main.php
```

## Upgrading
**From 1.1.0 to 1.2.0:** `MigrationManager`'s constructor takes an optional version table name and schema. Nothing
changes unless they're given.
