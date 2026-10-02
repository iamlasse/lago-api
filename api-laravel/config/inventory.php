<?php

declare(strict_types=1);

// Configuration for the coverage-ledger inventory (scripts/inventory + the
// inventory:check command). See scripts/inventory/README.md.

return [

    /*
    |--------------------------------------------------------------------------
    | Rails source checkout
    |--------------------------------------------------------------------------
    |
    | Read-only checkout of getlago/lago-api the generators scan. Override per
    | environment with LAGO_RAILS_PATH (the default matches the exploration
    | clone documented in the port plan).
    |
    */

    'rails_path' => env('LAGO_RAILS_PATH', '/tmp/lago-api-exploration'),

    /*
    |--------------------------------------------------------------------------
    | Committed artifacts
    |--------------------------------------------------------------------------
    |
    | Where generated inventories, the coverage ledger and milestone scope
    | files live. `inventory:check` regenerates in memory and diffs against
    | these bytes.
    |
    */

    'output_path' => env('LAGO_INVENTORY_PATH', base_path('tests/inventory')),

    /*
    |--------------------------------------------------------------------------
    | GraphQL return-type truncation
    |--------------------------------------------------------------------------
    |
    | GraphQL rows keep the return type (a full introspection type reference
    | can be very long) truncated to this many characters.
    |
    */

    'graphql_return_max_chars' => 120,

];
