<?php

/*
 * A test migration (see testing/tests/MigrationManagerTest.php), which creates the package_schema.package_one
 * table, in the schema the manager creates for its version table.
 */

use PgSql\Connection;
use Programster\PgsqlMigrations\MigrationInterface;


final class TestPackageMigration001 implements MigrationInterface
{
    public function up(Connection $connectionResource) : void
    {
        pg_query($connectionResource, 'CREATE TABLE "package_schema"."package_one" (id INT)');
    }


    public function down(Connection $connectionResource) : void
    {
        pg_query($connectionResource, 'DROP TABLE "package_schema"."package_one"');
    }
}
