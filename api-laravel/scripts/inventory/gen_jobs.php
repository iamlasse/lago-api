#!/usr/bin/env php
<?php

declare(strict_types=1);

// Generator wrapper: jobs inventory → tests/inventory/jobs.json

require __DIR__.'/lib/util.php';
require __DIR__.'/lib/jobs.php';

$railsPath = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--rails-path=')) {
        $railsPath = mb_substr($arg, mb_strlen('--rails-path='));
    }
}

try {
    $rows = inv_gen_jobs(inv_rails_path($railsPath));
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'gen_jobs: '.$exception->getMessage().PHP_EOL);

    exit(1);
}

$path = inv_write_artifact('jobs.json', inv_artifact(
    'gen_jobs',
    'app/jobs/**/*.rb',
    $rows
));

echo count($rows), " jobs -> {$path}\n";
