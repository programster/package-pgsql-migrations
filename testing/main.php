<?php

/*
 * Runs the smoke tests in testing/tests/ against a throwaway PostgreSQL database, given by the TEST_DB_HOST,
 * TEST_DB_PORT (default 5432), TEST_DB_NAME, TEST_DB_USER and TEST_DB_PASSWORD environment variables.
 * WARNING: the tests drop and re-create the database's public schema, so never point them at a real database.
 * Run with: php -d zend.assertions=1 testing/main.php
 * Exits with 0 if everything passed, or 1 if anything failed.
 */

require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/SmokeTest.php');

use Programster\PgsqlMigrations\Testing\SmokeTest;


# The tests check things with assert(), which does nothing when zend.assertions is 0 or -1, so every test would pass
# without checking anything. It can be switched from 0 to 1 here, but -1 (the usual production setting) can only be
# changed in php.ini or on the command line.
if (ini_get('zend.assertions') !== '1' && @ini_set('zend.assertions', '1') === false)
{
    echo "zend.assertions must be 1 for the tests to check anything. Run: php -d zend.assertions=1 " . __FILE__ . "\n";
    exit(1);
}

ini_set('assert.exception', '1');
$smoke = new SmokeTest();
$testFiles = glob(__DIR__ . '/tests/*Test.php');

if ($testFiles === false)
{
    echo "Couldn't list the test files in " . __DIR__ . "/tests.\n";
    exit(1);
}

sort($testFiles);

foreach ($testFiles as $testFile)
{
    require $testFile;
}

$failures = $smoke->getFailures();

# With MIGRATIONS_TEST_ONLY, a name that matches nothing would otherwise pass without checking anything.
$only = getenv("MIGRATIONS_TEST_ONLY");

if ($only !== false && $only !== "" && $smoke->getNumPassed() === 0 && count($failures) === 0)
{
    $failures[] = "MIGRATIONS_TEST_ONLY: no test's name contains \"{$only}\", so nothing was run.";
}

printf("PHP %s: %d passed, %d failed\n", PHP_VERSION, $smoke->getNumPassed(), count($failures));

foreach ($failures as $failure)
{
    echo "  FAIL {$failure}\n";
}

exit(count($failures) === 0 ? 0 : 1);
