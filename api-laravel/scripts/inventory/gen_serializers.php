#!/usr/bin/env php
<?php

declare(strict_types=1);

// Generator wrapper: serializers inventory → tests/inventory/serializers.json

require __DIR__.'/lib/util.php';
require __DIR__.'/lib/serializers.php';

$railsPath = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--rails-path=')) {
        $railsPath = mb_substr($arg, mb_strlen('--rails-path='));
    }
}

try {
    $rows = inv_gen_serializers(inv_rails_path($railsPath));
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'gen_serializers: '.$exception->getMessage().PHP_EOL);

    exit(1);
}

$path = inv_write_artifact('serializers.json', inv_artifact(
    'gen_serializers',
    'app/serializers/**/*.rb',
    $rows
));

echo count($rows), " serializers -> {$path}\n";
