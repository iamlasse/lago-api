<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Contract test for the metrics_extras scenario, captured from the real
 * Rails API by scripts/contract/capture.sh metrics_extras.
 *
 * Covers the billable_metrics extras beyond the CRUD scenario: the
 * evaluate_expression endpoint (success — the lago-expression gem renders
 * BigDecimal results in F-notation, so the JSON value is the STRING "21.0";
 * event.timestamp without a timestamp falls back to the frozen clock and
 * renders "1749740400.0"; the empty-expression value_is_mandatory, the
 * invalid_expression and the invalid_event 422 envelopes), PATCH semantics
 * on a metric (partial update — expression/rounding set, seeded filters
 * untouched), the PUT filters BATCH upsert (the whole values list replaced
 * and sorted, a new key appended) and the show-after.
 */
class MetricsExtrasTest extends ContractCase
{
    protected string $scenario = 'metrics_extras';

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
