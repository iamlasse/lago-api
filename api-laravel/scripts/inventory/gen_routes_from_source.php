#!/usr/bin/env php
<?php

declare(strict_types=1);

// INTERIM generator wrapper: REST route inventory parsed STATICALLY from
// config/routes*.rb → tests/inventory/rest.json.
//
// THIS IS PROVISIONAL. The plan mandates a live route dump
// (gen_routes.rb inside Rails) — see scripts/inventory/README.md. Every row
// in the artifact is flagged "provisional": true.

require __DIR__.'/lib/util.php';
require __DIR__.'/lib/routes.php';

$railsPath = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--rails-path=')) {
        $railsPath = mb_substr($arg, mb_strlen('--rails-path='));
    }
}

try {
    $rows = inv_gen_routes_from_source(inv_rails_path($railsPath));
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'gen_routes_from_source: '.$exception->getMessage().PHP_EOL);

    exit(1);
}

$path = inv_write_artifact('rest.json', inv_artifact(
    'gen_routes_from_source',
    'config/routes.rb + config/routes/{shared_api,plan_nested_api}.rb (STATIC parse — not authoritative)',
    $rows,
    provisional: true
));

echo count($rows), " REST routes (PROVISIONAL — static parse) -> {$path}\n";
