<?php

/**
 * This is a class to handle database migrations.
 *
 * This logic relies on a table called 'migrations' in the database for version info. If it does
 * not yet exist, then it will be created and the database will be considered to be at version 0
 */

namespace Programster\PgsqMigrations;


class MigrationManager
{
    const MIGRATIONS_TABLE = 'migrations';

    private $m_connection; #  A connection resource to the PostgreSQL database.
    private $m_migrationFolder; # The folder in which migration scripts are located.


    /**
     * Creates the Migration object in preparation for migration.
     * @param type $migrationFolderPath - the path to the folder containing all the migration scripts
     *                                this may be absolute or relative.
     * @param type $connection - a Mysqli object connecting us to the database.
     */
    public function __construct(string $migrationFolderPath, \mysqli $connection)
    {
        if (!is_dir($migrationFolderPath))
        {
            throw new \Exception("A migration folder does not exist at: {$migrationFolderPath}");
        }

        $this->m_migrationFolder = $migrationFolderPath;
        $this->m_connection = $connection;
    }


    /**
     * Migrates the database to the specified version. If the version is not specified (null) then
     * this will automatically migrate the database to the furthest point which is determined by
     * looking at the schemas.
     *
     * @param $version - optional parameter to specify the version we wish to migrate to.
     *                   if not set, then this will automatically migrate to the latest version
     *                   which is discovered by looking at the files.
     * @return void - updates database.
     */
    public function migrate($desiredVersion = null)
    {
        $databaseVersion = intval($this->getDbVersion());
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
     * @param void
     * @return Array<int,string> $keyedFiles - map of verstion/filepath to migration script
     * @throws Exception if two files have same version or there is a gap in versions.
     */
    private function getMigrationFiles()
    {
        // Find all the migration files in the directory and return the sorted.
        $files = scandir($this->m_migrationFolder);

        $keyedFiles = array();

        foreach ($files as $filename)
        {
            if (!is_dir($this->m_migrationFolder . '/' . $filename))
            {
                $fileVersion = self::getVileVersion($filename);

                if (isset($keyedFiles[$fileVersion]))
                {
                    throw new \Exception('Migration error: two files have the same version!');
                }

                $keyedFiles[$fileVersion] = $this->m_migrationFolder . '/' . $filename;
            }
        }

        ksort($keyedFiles);

        # Check that the migration files dont have gaps which could be the result of human error.
        $cachedVersion = null;

        $versions = array_keys($keyedFiles);

        foreach ($versions as $version)
        {
            if ($cachedVersion !== null)
            {
                if ($version != ($cachedVersion + 1))
                {
                    throw new \Exception('There is a gap in your migration file versions!');
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
     * @param filepath
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

            throw new \Exception($errMsg);
        }
        elseif (count($newClasses) > 1)
        {
            $errMsg = 'Migration error: Found more than 1 class defined in the migration script ' .
                       '[' . $filepath . ']';

            throw new \Exception('Migration error:');
        }

        # newClasses array keeps its keys, so the first element is not at 0 at this point
        $newClasses = array_values($newClasses);
        return $newClasses[0];
    }



    /**
     * Function responsible for deciphering the 'version' from a filename. This is a function
     * because we may wish to change it easily.
     * @param string $filename - the name of the file (not full path) that is a migration class.
     * @return int $version - the version the file represents.
     */
    private static function getVileVersion($filename)
    {
        $version = intval($filename);
        return $version;
    }


    /**
     * Inserts the specified version number into the database.
     * @param int $version - the new version of the database.
     * @return void.
     */
    private function insertDbVersion(int $version)
    {
        $escapedTableName = pg_escape_identifier(self::MIGRATIONS_TABLE);

        $query =
            "REPLACE INTO {$escapedTableName} " .
            "SET " . pg_escape_identifier("id") . " = 1" .
            ", " . pg_escape_identifier("version") . " = {$version}";

        $result = $this->m_connection->query($query);

        if ($result === false)
        {
            throw new \Exception("Migrations: error inserting version into the database");
        }
    }


    /**
     * Fetches the version of the database from the database.
     * @param void
     * @return int $version - the version in the dataase if it exists, -1 if it doesnt.
     * @throws Exception if migration table exists but failed to fetch version.
     */
    private function getDbVersion()
    {
        $showTablesQuery =
            "SELECT table_name FROM information_schema.tables" .
            " WHERE table_schema='public'" .
            " AND table_type='BASE TABLE'" .
            " AND table_name={$this->getEscapedMigrationTableName()}";

        $result = $this->m_connection->query($showTablesQuery);

        if ($result->num_rows > 0)
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
                $row = $result->fetch_assoc();

                if ($row == null || !isset($row['version']))
                {
                    throw new \Exception('Migrations: error reading database version from database');
                }

                $version = $row['version'];
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
     * Creates the migration table for if it doesnt exist yet to store the version within.
     * @param void
     * @return void.
     */
    private function createMigrationTable() : void
    {
        $createTableQuery =
            "CREATE TABLE {$this->getEscapedMigrationTableName()} (
                id INT NOT NULL,
                version INT NOT NULL
                PRIMARY KEY (id)
            )";

        $result = pg_query($this->m_connection, $createTableQuery);

        if ($result === false)
        {
            throw new \Exception("Migration manager failed to create the migrations table");
        }
    }


    private function getEscapedMigrationTableName() : string { return pg_escape_identifier(self::MIGRATIONS_TABLE); }
}

