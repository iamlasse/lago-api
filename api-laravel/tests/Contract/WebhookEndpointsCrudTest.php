<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Contract test for the webhook_endpoints_crud scenario, captured from the
 * real Rails API by scripts/contract/capture.sh webhook_endpoints_crud.
 *
 * Covers the REST webhook_endpoints surface: create with explicit event
 * types, create with event_types ["*"] (normalized to null — filtering
 * DISABLED), the index, show / update of a MINTED endpoint (tokenized ids),
 * invalid event types 422 (the offenders embedded IN the message: "contains
 * invalid types: [\"not_a_real_event\"]"), a SCALAR event_types surviving
 * params.permit on purpose so the model raises must_be_array, destroy of the
 * second minted endpoint and the index after.
 *
 * Requests #4/#5 address the endpoint minted by request #1 and request #8
 * the one minted by request #2 — their ids are minted per runtime, so the
 * manifest carries the WEBHOOK_ENDPOINT_*_ID tokens and this test
 * substitutes the ids its own requests #1 and #2 minted.
 */
class WebhookEndpointsCrudTest extends ContractCase
{
    protected string $scenario = 'webhook_endpoints_crud';

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

    protected function substituteRequestValues(array $request, int $oneBasedIndex, array $responses): array
    {
        $endpointOneId = ($responses[1] ?? null)?->json('webhook_endpoint.lago_id');
        $endpointTwoId = ($responses[2] ?? null)?->json('webhook_endpoint.lago_id');

        $map = [];

        if ($endpointOneId !== null) {
            $map['WEBHOOK_ENDPOINT_ONE_ID'] = $endpointOneId;
        }

        if ($endpointTwoId !== null) {
            $map['WEBHOOK_ENDPOINT_TWO_ID'] = $endpointTwoId;
        }

        return $this->replaceTokens($request, $map);
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
