<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Contract test for the analytics_gross_revenue scenario, captured from the
 * real Rails API by scripts/contract/capture.sh analytics_gross_revenue.
 *
 * GET /api/v1/analytics/gross_revenue — NOT premium-gated (unlike mrr /
 * invoiced_usage / invoice_collection). Seven requests: unfiltered, the
 * currency filter (lowercase eur is upcased by the controller), the USD
 * filter, the external-customer filter, the billing_entity_code filter,
 * months=3 (EMPTY by design — the months window is relative to the
 * Postgres DATABASE clock, not the frozen travel_to clock) and months=24.
 *
 * CLOCK WARNING: the analytics models run CURRENT_DATE / now() inside
 * Postgres, so the goldens are relative to the CAPTURE month. If the
 * calendar month has rolled past the capture month (see the empty-month
 * tails of the all-months series), re-capture the scenario — do not
 * normalize (scripts/contract/README.md, analytics gotchas).
 */
class AnalyticsGrossRevenueTest extends ContractCase
{
    protected string $scenario = 'analytics_gross_revenue';

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
