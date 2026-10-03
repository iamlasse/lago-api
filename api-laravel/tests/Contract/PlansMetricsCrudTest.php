<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Contract test for the plans_metrics_crud scenario, captured from the real
 * Rails API by scripts/contract/capture.sh plans_metrics_crud.
 *
 * Covers billable metrics CRUD (create / index / show / update / destroy)
 * plus plans CRUD with nested charges (standard, graduated, filtered), the
 * charge-filters index, and soft-destroy of a metric attached to a plan.
 */
class PlansMetricsCrudTest extends ContractCase
{
    protected string $scenario = 'plans_metrics_crud';

    protected function setUp(): void
    {
        parent::setUp();

        // Rails' capture ran with the :test ActiveJob adapter — jobs were
        // recorded, never executed.
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
