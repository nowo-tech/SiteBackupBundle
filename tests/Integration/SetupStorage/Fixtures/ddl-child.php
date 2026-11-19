<?php

declare(strict_types=1);

/*
 * Child process of RealDatabaseSetupStorageTest::testConcurrentFirstUseCreatesTheTablesOnce().
 *
 * argv: <DBAL params as JSON> <progress table> <step table> <start at (unix time, float)>
 * Waits for the common start time, then runs the first load() (the runtime DDL of both tables) and prints "ok" or the
 * error class.
 */

use Nowo\SiteBackupBundle\Setup\Storage\DoctrineDbalSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\DoctrineDbalSetupStepJournal;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

[, $paramsJson, $progressTable, $stepTable, $startAt] = $argv;

/** @var array<string, mixed> $params */
$params = json_decode($paramsJson, true, 512, \JSON_THROW_ON_ERROR);
// doctrine/dbal is a soft dependency (absent from the static analysis install): class names built at run time.
$dbal = static fn (string $class): string => 'Doctrine\\DBAL\\' . $class;
/** @var class-string $driverManager */
$driverManager = $dbal('DriverManager');
/** @var mixed $connection */
$connection = $driverManager::getConnection($params);
$platform   = $connection->getDatabasePlatform();
if (is_a($platform, $dbal('Platforms\\AbstractMySQLPlatform'))) {
    $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 5, lock_wait_timeout = 5');
} elseif (is_a($platform, $dbal('Platforms\\PostgreSQLPlatform'))) {
    $connection->executeStatement("SET lock_timeout = '5s'");
    $connection->executeStatement("SET statement_timeout = '30s'");
} else {
    $connection->executeStatement('PRAGMA busy_timeout = 5000');
}

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

$journal = new DoctrineDbalSetupStepJournal($connection, $stepTable);
$storage = new DoctrineDbalSetupProgressStorage($connection, $progressTable, $journal);

try {
    $storage->loadOrFail();
    // clear() lets a DDL error through (listCompletedStepIds() swallows it); there are no rows to delete.
    $journal->clear('sbddl-none');
    echo 'ok';
} catch (Throwable $e) {
    // The parent only checks the output.
    echo $e::class;
}
