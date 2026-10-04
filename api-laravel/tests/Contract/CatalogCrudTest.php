<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Contract test for the catalog_crud scenario, captured from the real Rails
 * API by scripts/contract/capture.sh catalog_crud.
 *
 * Covers the /api/v2 catalog surface: product_categories, products
 * (index/show/update), rate_cards with a standard rate (index/show), a
 * contract against a catalog plan (created PENDING via a future started_at
 * — contracts are only editable while pending), its applied rate card
 * (create/index/show/update with a units change) and a bounded rate phase
 * with a rate_override. Every request requires the
 * `X-Lago-Endpoint-Status: beta` header.
 */
class CatalogCrudTest extends ContractCase
{
    protected string $scenario = 'catalog_crud';

    protected function setUp(): void
    {
        parent::setUp();

        // Rails' capture ran with the :test ActiveJob adapter — jobs were
        // recorded, never executed (catalog/contract webhooks).
        Queue::fake();
    }

    public function test_replays_the_captured_requests_against_the_goldens(): void
    {
        $this->runScenario();
    }

    /**
     * Resets every table before loading the fixture so repeated runs (and
     * Laravel's own rows from a previous replay) cannot collide with the
     * captured primary keys. The `migrations` bookkeeping table is kept.
     */
    protected function loadFixture(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('organizations')) {
            $this->artisan('migrate', ['--force' => true]);
        }

        $tables = DB::select(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'migrations'"
        );

        foreach ($tables as $table) {
            DB::statement('TRUNCATE TABLE public.'.data_get($table, 'tablename').' CASCADE');
        }

        parent::loadFixture();

        DB::statement('SET search_path TO public');
    }
}
