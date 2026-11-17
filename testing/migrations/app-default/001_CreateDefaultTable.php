<?php

/*
 * A test migration (see testing/tests/MigrationManagerTest.php), which creates the default_one table.
 */

use PgSql\Connection;
use Programster\PgsqlMigrations\MigrationInterface;


final class TestDefaultMigration001 implements MigrationInterface
{
    public function up(Connection $connectionResource) : void
    {
        pg_query($connectionResource, 'CREATE TABLE default_one (id INT)');
    }


    public function down(Connection $connectionResource) : void
    {
        pg_query($connectionResource, 'DROP TABLE default_one');
    }
}
