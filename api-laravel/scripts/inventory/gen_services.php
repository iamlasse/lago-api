#!/usr/bin/env php
<?php

declare(strict_types=1);

// Generator wrapper: services inventory → tests/inventory/services.json

require __DIR__.'/lib/util.php';
require __DIR__.'/lib/services.php';

$railsPath = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--rails-path=')) {
        $railsPath = mb_substr($arg, mb_strlen('--rails-path='));
    }
}

try {
    $rows = inv_gen_services(inv_rails_path($railsPath));
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'gen_services: '.$exception->getMessage().PHP_EOL);

    exit(1);
}

$path = inv_write_artifact('services.json', inv_artifact(
    'gen_services',
    'app/services/**/*.rb',
    $rows
));

echo count($rows), " services -> {$path}\n";
