<?php

/**
 * This is a class to handle database migrations.
 *
 * This logic relies on a table called 'migrations' in the database for version info. If it does
 * not yet exist, then it will be created and the database will be considered to be at version 0
 */

namespace Programster\PgsqlMigrations;


use Exception;
use PgSql\Connection;

class MigrationManager
{
    const MIGRATIONS_TABLE = 'migrations';

    private Connection $m_connection; #  A connection resource to the PostgreSQL database.
    private string $m_migrationFolder; # The folder in which migration scripts are located.


    /**
     * Creates the Migration object in preparation for migration.
     * @param string $migrationFolderPath - the path to the folder containing all the migration scripts. This may be
     * absolute or relative.
     * @param Connection $connection - the postgresql connection resource
     * @throws Exception
     */
    public function __construct(string $migrationFolderPath, Connection $connection)
    {
        if (!is_dir($migrationFolderPath))
        {
            throw new Exception("A migration folder does not exist at: {$migrationFolderPath}");
        }

        $this->m_migrationFolder = $migrationFolderPath;
        $this->m_connection = $connection;
    }


    /**
     * Migrates the database to the specified version. If the version is not specified (null) then this will
     * automatically migrate the database to the furthest point which is determined by looking at the schemas.
     *
     * @param $desiredVersion - optional parameter to specify the version we wish to migrate to. If not set, then this
     * will automatically migrate to the latest version which is discovered by looking at the files.
     *
     * @return void - updates database.
     * @throws Exception
     */
    public function migrate($desiredVersion = null) : void
    {
        $databaseVersion = $this->getDbVersion();
        $migrationFiles = $this->getMigrationFiles();

        if ($desiredVersion === null)
        {
            end($migrationFiles); # move the internal pointer to the end of the array
            $desiredVersion = intval(key($migrationFiles));
        }

        if ($desiredVersion !== $databaseVersion)
        {
            if ($desiredVersion > $databaseVersion)
            {
                # Performing an upgrade
                foreach ($migrationFiles as $migrationFileVersion => $filepath)
                {
                    if
                    (
                           $migrationFileVersion > $databaseVersion
                        && $migrationFileVersion <= $desiredVersion
                    )
                    {
                        $className = self::includeFileAndGetClassName($filepath);

                        /* @var $migrationObject MigrationInterface */
                        $migrationObject = new $className();
                        $migrationObject->up($this->m_connection);

                        # Update the version after every successful migration in case a later one
                        # fails
                        $this->insertDbVersion($migrationFileVersion);
                    }
                }
            }
            else
            {
                # performing a downgrade
                krsort($migrationFiles);

                foreach ($migrationFiles as $migrationFileVersion => $filepath)
                {
                    if
                    (
                          $migrationFileVersion <= $databaseVersion
                        && $migrationFileVersion > $desiredVersion
                    )
                    {
                        $className = self::includeFileAndGetClassName($filepath);

                        /* @var $migrationObject MigrationInterface */
                        $migrationObject = new $className();
                        $migrationObject->down($this->m_connection);

                        # Update the version after every successful migration in case a later one
                        # fails
                        $this->insertDbVersion(($migrationFileVersion - 1));
                    }
                }
            }
        }
    }


    /**
     * Fetches the migration files from the migrations folder.
     * @return array<int,string> $keyedFiles - map of version/filepath to migration script
     * @throws Exception if two files have same version or there is a gap in versions.
     */
    private function getMigrationFiles() : array
    {
        // Find all the migration files in the directory and return the sorted.
        $files = scandir($this->m_migrationFolder);

        $keyedFiles = array();

        foreach ($files as $filename)
        {
            if (!is_dir($this->m_migrationFolder . '/' . $filename))
            {
                $fileVersion = self::getFileVersion($filename);

                if (isset($keyedFiles[$fileVersion]))
                {
                    throw new Exception('Migration error: two files have the same version!');
                }

                $keyedFiles[$fileVersion] = $this->m_migrationFolder . '/' . $filename;
            }
        }

        ksort($keyedFiles);

        # Check that the migration files don't have gaps which could be the result of human error.
        $cachedVersion = null;

        $versions = array_keys($keyedFiles);

        foreach ($versions as $version)
        {
            if ($cachedVersion !== null)
            {
                if ($version != ($cachedVersion + 1))
                {
                    throw new Exception('There is a gap in your migration file versions!');
                }

                $cachedVersion = $version;
            }
        }

        return $keyedFiles;
    }


    /**
     * Given a file that has NOT already been included, this function will return the name
     * of the class within that file AFTER having included it.
     * Warning: This function works on the assumption that only one class is defined in the
     * migration script!
     * @param string $filepath - the path to the file
     * @throws Exception
     */
    private function includeFileAndGetClassName(string $filepath)
    {
        $existingClasses = get_declared_classes();
        require_once($filepath);
        $afterClasses = get_declared_classes();
        $newClasses = array_diff($afterClasses, $existingClasses);

        if (count($newClasses) == 0)
        {
            $errMsg = 'Migration error: Could not find new class from including migration script' .
                      '. This could be caused by having duplicate class names, or having already ' .
                      'included the migration script.';

            throw new Exception($errMsg);
        }
        elseif (count($newClasses) > 1)
        {
            $errMsg = 'Migration error: Found more than 1 class defined in the migration script ' .
                       '[' . $filepath . ']';

            throw new Exception($errMsg);
        }

        # newClasses array keeps its keys, so the first element is not at 0 at this point
        $newClasses = array_values($newClasses);
        return $newClasses[0];
    }



    /**
     * Function responsible for deciphering the 'version' from a filename. This is a function because we may wish to
     * change it easily.
     * @param string $filename - the name of the file (not full path) that is a migration class.
     * @return int $version - the version the file represents.
     */
    private static function getFileVersion(string $filename) : int
    {
        return intval($filename);
    }


    /**
     * Inserts the specified version number into the database.
     * @param int $version - the new version of the database.
     * @return void.
     * @throws Exception
     */
    private function insertDbVersion(int $version) : void
    {
        $escapedTableName = pg_escape_identifier($this->m_connection, self::MIGRATIONS_TABLE);

        // Postgresql does not have REPLACE, so just delete and insert in one transaction.
        $query =
            "DELETE FROM {$escapedTableName};" .
            " INSERT INTO {$escapedTableName}" .
            " (" . pg_escape_identifier($this->m_connection, "id") . ", " . pg_escape_identifier($this->m_connection, "version")  . ")" .
            " VALUES (1, {$version})";

        $result = pg_query($this->m_connection, $query);

        if ($result === false)
        {
            throw new Exception("Migrations: error inserting version into the database");
        }
    }


    /**
     * Fetches the version of the database from the database.
     * @return int $version - the version in the database if it exists, -1 if it doesn't.
     * @throws Exception if migration table exists but failed to fetch version.
     */
    private function getDbVersion() : int
    {
        $showTablesQuery =
            "SELECT table_name FROM information_schema.tables" .
            " WHERE table_schema='public'" .
            " AND table_type = 'BASE TABLE'" .
            " AND table_name = " . pg_escape_literal($this->m_connection, self::MIGRATIONS_TABLE);

        $result = pg_query($this->m_connection, $showTablesQuery);

        if (pg_num_rows($result) > 0)
        {
            $selectMigrationsQuery = "SELECT * FROM {$this->getEscapedMigrationTableName()}";
            $result = pg_query($this->m_connection, $selectMigrationsQuery);

            if ($result === FALSE || pg_num_rows($result) === 0)
            {
                # Appears that we have the migrations table but no version row, which may be the
                # result of a previously erroneous upgrade attempt, so return that no version is set.
                $version = -1;
            }
            else
            {
                $row = pg_fetch_assoc($result);

                if ($row == null || !isset($row['version']))
                {
                    throw new Exception('Migrations: error reading database version from database');
                }

                $version = intval($row['version']);
            }
        }
        else
        {
            $this->createMigrationTable();
            $version = -1; # just in case the users migration files start at 0 and not 1
        }

        return $version;
    }


    /**
     * Creates the migration table for if it doesn't exist yet to store the version within.
     * @return void.
     * @throws Exception
     */
    private function createMigrationTable() : void
    {
        $createTableQuery =
            "CREATE TABLE {$this->getEscapedMigrationTableName()} (
                id INT NOT NULL,
                version INT NOT NULL,
                PRIMARY KEY (id)
            )";

        $result = pg_query($this->m_connection, $createTableQuery);

        if ($result === false)
        {
            throw new Exception("Migration manager failed to create the migrations table");
        }
    }


    private function getEscapedMigrationTableName() : string
    {
        return pg_escape_identifier($this->m_connection, self::MIGRATIONS_TABLE);
    }
}

