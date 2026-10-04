<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Contract test for the coupons_lifecycle scenario, captured from the real
 * Rails API by scripts/contract/capture.sh coupons_lifecycle.
 *
 * Covers coupon create (fixed + percentage), index, show, update before and
 * AFTER application (the immutability contract: pricing fields are silently
 * ignored while the coupon has applied instances), applying to a customer,
 * the applied-coupons index, the customer-nested applied-coupon destroy,
 * and coupon destroy (which terminates its active applied coupons).
 *
 * Request #10 terminates the applied coupon minted by request #6; its id is
 * minted per runtime, so the manifest carries the FIXED_APPLIED_COUPON_ID
 * token and this test substitutes the id its own request #6 minted.
 */
class CouponsLifecycleTest extends ContractCase
{
    protected string $scenario = 'coupons_lifecycle';

    protected function setUp(): void
    {
        parent::setUp();

        // Rails' capture ran with the :test ActiveJob adapter — jobs were
        // recorded, never executed (coupon/applied-coupon webhooks).
        Queue::fake();
    }

    public function test_replays_the_captured_requests_against_the_goldens(): void
    {
        $this->runScenario();
    }

    protected function substituteRequestValues(array $request, int $oneBasedIndex, array $responses): array
    {
        $fixedAppliedId = ($responses[6] ?? null)?->json('applied_coupon.lago_id');

        if ($fixedAppliedId !== null) {
            $request = $this->replaceTokens($request, ['FIXED_APPLIED_COUPON_ID' => $fixedAppliedId]);
        }

        return $request;
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
