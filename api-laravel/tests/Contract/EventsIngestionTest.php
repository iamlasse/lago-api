<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Contract test for the events_ingestion scenario, captured from the real
 * Rails API by scripts/contract/capture.sh events_ingestion.
 *
 * Covers the events endpoint: plain create, duplicate transaction_id
 * (422 value_already_exist — the (organization, external_subscription,
 * transaction) unique index), missing code, an event against an EXPRESSION
 * billable metric (the evaluated `value * 2` lands in properties.total),
 * an expression event missing its input (422 expression_evaluation_failed),
 * a mixed batch (one invalid event fails the WHOLE batch with per-index
 * errors), a fully valid batch, show by transaction_id, and the index
 * (timestamp DESC).
 */
class EventsIngestionTest extends ContractCase
{
    protected string $scenario = 'events_ingestion';

    protected function setUp(): void
    {
        parent::setUp();

        // Rails' capture ran with the :test ActiveJob adapter — the events
        // post-process job (customer resolution) was recorded, never
        // executed: the goldens show lago_customer_id: null.
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
