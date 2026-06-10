#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Database migration / seed runner.
 *   php bin/migrate.php           apply schema.sql
 *   php bin/migrate.php --seed    apply schema.sql + seed.sql
 */

$root = dirname(__DIR__);
$config = require $root . '/config/config.php';
$db = $config['database'];

$dsnNoDb = sprintf('mysql:host=%s;port=%d;charset=%s', $db['host'], $db['port'], $db['charset']);
$pdo = new PDO($dsnNoDb, $db['username'], $db['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec(sprintf(
    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    str_replace('`', '', $db['database'])
));
$pdo->exec('USE `' . str_replace('`', '', $db['database']) . '`');

$runSqlFile = static function (PDO $pdo, string $path): void {
    echo "Applying: $path\n";
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Cannot read $path");
    }
    // Strip comment-only lines, then split on semicolons at end of statements.
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
};

$runSqlFile($pdo, $root . '/database/schema.sql');

if (in_array('--seed', $argv, true)) {
    $runSqlFile($pdo, $root . '/database/seed.sql');
}

echo "Done.\n";
