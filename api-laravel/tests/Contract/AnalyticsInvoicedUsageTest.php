<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Contract test for the analytics_invoiced_usage scenario, captured from
 * the real Rails API by scripts/contract/capture.sh
 * analytics_invoiced_usage.
 *
 * GET /api/v1/analytics/invoiced_usage — PREMIUM-GATED in the SERVICE
 * (Analytics::InvoicedUsagesService → forbidden_failure!). The scenario
 * captured request #1 UNGATED (the OSS 403 feature_unavailable envelope
 * IS the contract) and flipped the License singleton BETWEEN requests #1
 * and #2. The replay mirrors the flip through
 * ContractCase::requestEnvironment (the per-request config hook —
 * App\Support\License::premium() reads config('lago.license')).
 *
 * The goldens pin the Rails surprises the README documents: rows exist
 * only for months WITH data (IS NOT NULL filter — unlike mrr /
 * invoice_collection), rows GROUP BY fee.created_at (not invoice
 * issuing_date), the per-metric coupon subtraction
 * (amount_cents - precise_coupons_amount_cents) is exercised with a 500c
 * coupon, and `month` renders with the "...Z" format
 * (STRICT_DATETIME_FIELDS in the Normalizer: formats are per-endpoint
 * contract, not slack).
 *
 * CLOCK WARNING: the analytics models run CURRENT_DATE / now() inside
 * Postgres, so the goldens are relative to the CAPTURE month (the
 * months=3 golden is EMPTY by design). If the calendar month has rolled
 * past the capture month, re-capture the scenario — do not normalize
 * (scripts/contract/README.md, analytics gotchas).
 */
class AnalyticsInvoicedUsageTest extends ContractCase
{
    protected string $scenario = 'analytics_invoiced_usage';

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
     * The manifest's recorded premium flip: request #1 captured with the
     * OSS license-less stack, requests #2+ with the License singleton
     * flipped ON (see the class docblock).
     *
     * @return array<string, mixed>
     */
    protected function requestEnvironment(int $oneBasedIndex): array
    {
        if ($oneBasedIndex === 1) {
            return ['lago.license' => null];
        }

        return ['lago.license' => 'contract-capture-license'];
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
