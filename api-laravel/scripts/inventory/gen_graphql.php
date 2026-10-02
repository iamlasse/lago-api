#!/usr/bin/env php
<?php

declare(strict_types=1);

// Generator wrapper: GraphQL operations inventory → tests/inventory/graphql.json

require __DIR__.'/lib/util.php';
require __DIR__.'/lib/graphql.php';

$railsPath = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--rails-path=')) {
        $railsPath = mb_substr($arg, mb_strlen('--rails-path='));
    }
}

try {
    $rows = inv_gen_graphql(inv_rails_path($railsPath));
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'gen_graphql: '.$exception->getMessage().PHP_EOL);

    exit(1);
}

$path = inv_write_artifact('graphql.json', inv_artifact(
    'gen_graphql',
    'schema.json (Rails introspection result)',
    $rows
));

echo count($rows), " GraphQL operations -> {$path}\n";
