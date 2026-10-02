#!/usr/bin/env php
<?php

declare(strict_types=1);

// Runs every PHP inventory generator in sequence and prints per-artifact row
// counts. Does NOT run gen_routes.rb (that needs a booted Rails stack — see
// README.md); the interim static route parser runs in its place.

require __DIR__.'/lib/util.php';
require __DIR__.'/lib/graphql.php';
require __DIR__.'/lib/serializers.php';
require __DIR__.'/lib/jobs.php';
require __DIR__.'/lib/services.php';
require __DIR__.'/lib/tables.php';
require __DIR__.'/lib/routes.php';
require __DIR__.'/lib/ledger.php';

$railsPath = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--rails-path=')) {
        $railsPath = mb_substr($arg, mb_strlen('--rails-path='));
    }
}

$railsPath = inv_rails_path($railsPath);

/** @var array<string, array{generator: string, rows: list<array<string, mixed>>, source: string, provisional: bool}> $generators */
$generators = [
    'graphql.json' => ['generator' => 'gen_graphql', 'rows' => inv_gen_graphql($railsPath), 'source' => 'schema.json (Rails introspection result)', 'provisional' => false],
    'serializers.json' => ['generator' => 'gen_serializers', 'rows' => inv_gen_serializers($railsPath), 'source' => 'app/serializers/**/*.rb', 'provisional' => false],
    'jobs.json' => ['generator' => 'gen_jobs', 'rows' => inv_gen_jobs($railsPath), 'source' => 'app/jobs/**/*.rb', 'provisional' => false],
    'services.json' => ['generator' => 'gen_services', 'rows' => inv_gen_services($railsPath), 'source' => 'app/services/**/*.rb', 'provisional' => false],
    'tables.json' => ['generator' => 'gen_tables', 'rows' => inv_gen_tables($railsPath), 'source' => 'db/structure.sql CREATE TABLE blocks', 'provisional' => false],
    'rest.json' => ['generator' => 'gen_routes_from_source', 'rows' => inv_gen_routes_from_source($railsPath), 'source' => 'config/routes.rb + config/routes/{shared_api,plan_nested_api}.rb (STATIC parse — not authoritative)', 'provisional' => true],
];

$total = 0;

foreach ($generators as $name => $generator) {
    $path = inv_write_artifact($name, inv_artifact(
        $generator['generator'],
        $generator['source'],
        $generator['rows'],
        $generator['provisional']
    ));

    $total += count($generator['rows']);
    $flag = $generator['provisional'] ? ' [PROVISIONAL]' : '';

    echo mb_str_pad((string) count($generator['rows']), 6), $name, $flag, "\n";
}

// Join the fresh inventories with the Laravel app scan into the ledger.
// Existing rows keep their hand-maintained status/tests; only missing rows
// are seeded and stale rows dropped.
$ledgerPath = inv_seed_ledger($generators, $railsPath);

echo 'ledger -> ', $ledgerPath, "\n";
echo $total, ' inventory rows total (ledger refresh included)', "\n";
