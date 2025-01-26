<?php

/*
 * An interface for a migration script. Implement this when you are writing a migration.
 */

namespace Programster\PgsqlMigrations;


use PgSql\Connection;

interface MigrationInterface
{
    /**
     * Method to apply updates.
     * @param Connection $connectionResource - the pgsql connection to the database.
     */
    public function up(Connection $connectionResource) : void;


    /**
     * Method to apply updates.
     * @param Connection $connectionResource - the pgsql connection to the database.
     */
    public function down(Connection $connectionResource) : void;
}