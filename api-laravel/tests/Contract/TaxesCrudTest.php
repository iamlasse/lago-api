<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Contract test for the taxes_crud scenario, captured from the real Rails
 * API by scripts/contract/capture.sh taxes_crud.
 *
 * Covers the REST taxes surface: create (simple + applied_to_organization,
 * which attaches the tax to the org's billing entity), the index, show and
 * update by CODE, the org-wide detach (update applied_to_organization
 * false), the duplicate-code 422 (value_already_exist — singular, that is
 * Rails' uniqueness message verbatim), show of a missing code (404
 * tax_not_found) and destroy.
 */
class TaxesCrudTest extends ContractCase
{
    protected string $scenario = 'taxes_crud';

    protected function setUp(): void
    {
        parent::setUp();

        // Rails' capture ran with the :test ActiveJob adapter — jobs were
        // recorded, never executed (tax webhooks).
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
