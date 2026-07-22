#!/usr/bin/env php
<?php

declare(strict_types=1);

// Runs BEFORE Laravel ever boots — plain PDO, no framework bootstrap — so
// the canonical test runner (composer test) can be invoked twice in a row
// with no external manual preparation (#138 PR4 ola4A round 4 review).
// Drops and recreates DB_DATABASE, then grants DB_USERNAME access to it,
// so the very next command (vendor/bin/pest) always starts from a
// database no prior process has ever touched — the exact contract
// TestBootstrapHelper::assertDatabaseNotAlreadyBootstrapped() enforces.
//
// Same allowlist and fail-closed guards as TestBootstrapHelper: refuses to
// run outside APP_ENV=testing, refuses to run against a database not
// explicitly named in TEST_BOOTSTRAP_ALLOWED_DATABASES, and requires an
// explicit root credential pair rather than assuming one.

function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);

    exit(1);
}

$appEnv = getenv('APP_ENV');

if ($appEnv !== 'testing') {
    fail("reset-test-database.php refuses to run outside APP_ENV=testing. Current: {$appEnv}");
}

$database = getenv('DB_DATABASE');

if ($database === false || $database === '') {
    fail('DB_DATABASE is required.');
}

$allowedDatabases = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) getenv('TEST_BOOTSTRAP_ALLOWED_DATABASES')),
)));

if ($allowedDatabases === [] || ! in_array($database, $allowedDatabases, true)) {
    fail(
        "Refusing to reset \"{$database}\" — it is not in TEST_BOOTSTRAP_ALLOWED_DATABASES (currently: [".implode(', ', $allowedDatabases).']). Set that env var to the dedicated test database name before running this script. Same guard as TestBootstrapHelper::assertSafeToRunDestructiveBootstrap().'
    );
}

$rootUser = getenv('TEST_BOOTSTRAP_DB_ROOT_USER') ?: 'root';
$rootPassword = getenv('TEST_BOOTSTRAP_DB_ROOT_PASSWORD');

if ($rootPassword === false || $rootPassword === '') {
    fail('TEST_BOOTSTRAP_DB_ROOT_PASSWORD is required to reset the test database — set it to the MySQL root password before running this script.');
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$appUser = getenv('DB_USERNAME') ?: 'root';

try {
    $pdo = new PDO("mysql:host={$host};port={$port}", $rootUser, $rootPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    $pdo->exec('CREATE DATABASE `'.$database.'`');

    if ($appUser !== $rootUser) {
        $pdo->exec("GRANT ALL PRIVILEGES ON `{$database}`.* TO '".$appUser."'@'%'");
        $pdo->exec('FLUSH PRIVILEGES');
    }
} catch (PDOException $e) {
    fail('Failed to reset "'.$database.'": '.$e->getMessage());
}

echo "Reset {$database} — no manual preparation needed before the next command.".PHP_EOL;
