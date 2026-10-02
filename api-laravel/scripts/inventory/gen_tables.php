#!/usr/bin/env php
<?php

declare(strict_types=1);

// Generator wrapper: tables inventory → tests/inventory/tables.json

require __DIR__.'/lib/util.php';
require __DIR__.'/lib/tables.php';

$railsPath = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--rails-path=')) {
        $railsPath = mb_substr($arg, mb_strlen('--rails-path='));
    }
}

try {
    $rows = inv_gen_tables(inv_rails_path($railsPath));
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'gen_tables: '.$exception->getMessage().PHP_EOL);

    exit(1);
}

$path = inv_write_artifact('tables.json', inv_artifact(
    'gen_tables',
    'db/structure.sql CREATE TABLE blocks',
    $rows
));

echo count($rows), " tables -> {$path}\n";
