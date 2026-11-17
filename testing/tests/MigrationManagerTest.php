<?php

/*
 * Tests of MigrationManager: the default version table, a package's own version table and schema next to the
 * application's, and the names it rejects. Each migration folder can only be run once per process, as the manager
 * includes each migration's file to find its class, so each test uses folders of its own.
 */

use Programster\PgsqlMigrations\MigrationManager;
use Programster\PgsqlMigrations\Testing\SmokeTest;

/** @var SmokeTest $smoke */


/**
 * Connect to the test database, with its public schema emptied and the package's schema dropped, so each test
 * starts from nothing.
 * @return \PgSql\Connection
 */
function connectToEmptyTestDatabase() : \PgSql\Connection
{
    $connectionString = sprintf(
        "host=%s port=%s dbname=%s user=%s password=%s",
        getenv('TEST_DB_HOST'),
        getenv('TEST_DB_PORT') ?: '5432',
        getenv('TEST_DB_NAME'),
        getenv('TEST_DB_USER'),
        getenv('TEST_DB_PASSWORD')
    );

    $connection = pg_connect($connectionString, PGSQL_CONNECT_FORCE_NEW);
    assert($connection !== false, "Couldn't connect to the test database.");
    pg_query($connection, 'DROP SCHEMA public CASCADE; CREATE SCHEMA public');
    pg_query($connection, 'DROP SCHEMA IF EXISTS package_schema CASCADE');
    return $connection;
}


/**
 * Fetch the version a version table holds.
 * @param \PgSql\Connection $connection
 * @param string $escapedTable - the table, escaped and optionally schema-qualified.
 * @return int
 */
function fetchVersion(\PgSql\Connection $connection, string $escapedTable) : int
{
    $result = pg_query($connection, "SELECT version FROM {$escapedTable}");
    return intval(pg_fetch_result($result, 0, 'version'));
}


/**
 * Find out whether a table exists.
 * @param \PgSql\Connection $connection
 * @param string $schema
 * @param string $table
 * @return bool
 */
function tableExists(\PgSql\Connection $connection, string $schema, string $table) : bool
{
    $query = 'SELECT 1 FROM information_schema.tables WHERE table_schema = $1 AND table_name = $2';
    return (pg_num_rows(pg_query_params($connection, $query, [$schema, $table])) === 1);
}


$defaultTable = function () : void {
    $connection = connectToEmptyTestDatabase();
    $manager = new MigrationManager(__DIR__ . '/../migrations/app-default', $connection);
    $manager->migrate();

    assert(tableExists($connection, 'public', 'default_one'), "The migration should have run.");
    SmokeTest::assertSame(1, fetchVersion($connection, '"migrations"'), "the version in public.migrations");
};

$smoke->run("migrations: by default, the version is kept in public.migrations, as before", $defaultTable);

$packageNextToApp = function () : void {
    $connection = connectToEmptyTestDatabase();
    $appManager = new MigrationManager(__DIR__ . '/../migrations/app', $connection);

    $packageManager = new MigrationManager(
        __DIR__ . '/../migrations/package',
        $connection,
        'package_migrations',
        'package_schema'
    );

    $appManager->migrate();
    $packageManager->migrate();

    assert(tableExists($connection, 'public', 'app_one') && tableExists($connection, 'public', 'app_two'));
    assert(tableExists($connection, 'package_schema', 'package_one'), "The package's migration should have run.");
    SmokeTest::assertSame(2, fetchVersion($connection, '"migrations"'), "the app's version");

    $packageVersion = fetchVersion($connection, '"package_schema"."package_migrations"');
    SmokeTest::assertSame(1, $packageVersion, "the package's version");

    // Running both again changes nothing, as each knows its own version.
    $appManager->migrate();
    $packageManager->migrate();
    SmokeTest::assertSame(2, fetchVersion($connection, '"migrations"'), "the app's version, after migrating again");
    $packageVersionAgain = fetchVersion($connection, '"package_schema"."package_migrations"');
    SmokeTest::assertSame(1, $packageVersionAgain, "the package's version, after migrating again");
    assert(tableExists($connection, 'public', 'package_migrations') === false, "It belongs in package_schema.");
};

$smoke->run("migrations: a package's own version table and schema don't clash with the app's", $packageNextToApp);

$badNames = function () : void {
    $connection = connectToEmptyTestDatabase();
    $folder = __DIR__ . '/../migrations/app';

    $badTableNames = ['', str_repeat('a', 64), "a\0b"];

    foreach ($badTableNames as $badName)
    {
        $createWithBadTable = function () use ($folder, $connection, $badName) : void {
            new MigrationManager($folder, $connection, $badName);
        };

        SmokeTest::expectException(\Exception::class, $createWithBadTable, "version table name");

        $createWithBadSchema = function () use ($folder, $connection, $badName) : void {
            new MigrationManager($folder, $connection, 'package_migrations', $badName);
        };

        SmokeTest::expectException(\Exception::class, $createWithBadSchema, "version table schema");
    }

    // 63 bytes is PostgreSQL's longest identifier, so it's allowed.
    new MigrationManager($folder, $connection, str_repeat('a', 63), str_repeat('b', 63));
};

$smoke->run("migrations: an empty, over-long or NUL-containing table or schema name is rejected", $badNames);
