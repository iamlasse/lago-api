<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The frozen-schema migration creates Postgres enum types, which survive
 * Laravel's migrate:fresh (it only drops tables). Reset the whole schema and
 * re-run the loader before the per-test transaction begins.
 *
 * Composing (and so overriding within the trait flattening) is required:
 * Pest applies the trait to the generated test class, whose methods would
 * shadow any same-named method inherited from Tests\TestCase.
 */
trait FrozenSchemaDatabase
{
    use RefreshDatabase;

    protected function refreshTestDatabase()
    {
        DB::statement('DROP SCHEMA public CASCADE');
        DB::statement('CREATE SCHEMA public');

        $this->artisan('migrate', ['--force' => true]);

        $this->beginDatabaseTransaction();
    }
}
