<?php

/*
 * An interface for a migration script. Implement this when you are writing a migration.
 */

namespace Programster\PgsqlMigrations;


interface MigrationInterface
{
    /**
     * Method to apply updates.
     * @param resource $connectionResource - the pgsql connection to the database.
     */
    public function up($connectionResource) : void;


    /**
     * Method to apply updates.
     * @param resource $connectionResource - the pgsql connection to the database.
     */
    public function down($connectionResource) : void;
}