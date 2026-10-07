<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Models\Invoice;
use Carbon\CarbonImmutable;
use App\Models\Subscription;
use App\Jobs\BillSubscriptionJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Shared replay flow for the per-charge-model invoice scenarios
 * (invoice_graduated, invoice_package, invoice_percentage, invoice_volume,
 * invoice_graduated_percentage — one per charge model).
 *
 * Every scenario captures the same two manifest requests:
 *   #1 POST /api/v1/subscriptions — arrears calendar-monthly subscription on
 *      a plan whose only usage charge is the model under test (no invoice
 *      yet), then the billing itself, which is NOT an HTTP call (production
 *      bills from the clock):
 *   #2 GET /api/v1/invoices?external_customer_id=… — the minted invoice,
 *      summarized.
 * plus EXTRA golden 10.json: GET /api/v1/invoices/<minted id> — the full
 * invoice incl. fees[].amount_details, the fidelity target (graduated
 * tiers, package units, percentage free/paid split, volume ranges).
 *
 * Both runtimes trigger the production billing entry point in-process at
 * the frozen period boundary:
 *   Rails capture:  BillSubscriptionJob.perform_now([subscription], ts,
 *                     invoicing_reason: :subscription_periodic)
 *   Laravel replay: (new BillSubscriptionJob([$subscription], ts,
 *                     'subscription_periodic'))->handle()
 *
 * Metered input is seeded — three Event rows riding fixture.sql (see each
 * scenario .rb) PLUS one cached_aggregations row carrying the same sum:
 * the Laravel aggregation seam (app/Services/Fees/ChargeService/Aggregator.php)
 * reads cached_aggregations, not events (live aggregation is M2 there),
 * while Rails ignores cached rows on the arrears periodic path — so both
 * sides aggregate over the same units without either side's events endpoint.
 */
abstract class InvoiceChargeModelCase extends ContractCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Rails' capture ran with the :test ActiveJob adapter — jobs were
        // recorded, never executed (billing enqueues webhook/PDF jobs).
        Queue::fake();
    }

    /** The scenario's customer external_id (same as the .rb constant). */
    abstract protected function customerExternalId(): string;

    /** The scenario's subscription external_id (same as the .rb constant). */
    abstract protected function subscriptionExternalId(): string;

    /** The frozen billing instant, matching the .rb's BILLING_AT. */
    abstract protected function billingAt(): string;

    public function test_replays_the_captured_requests_against_the_goldens(): void
    {
        // 1. subscription create (arrears — nothing billed at creation).
        $this->replay($this->manifest['requests'][0]);

        // In-process billing at the frozen period boundary.
        $this->billInProcess();

        // 2. invoice index — the minted invoice, summarized.
        $this->assertMatchesGolden($this->replay($this->manifest['requests'][1]), 2);

        // EXTRA golden 10.json: show of the invoice minted by the in-process
        // billing, addressed by the id IT minted (see the scenario scripts).
        $invoiceId = $this->mintedInvoiceId();

        $this->assertMatchesGolden($this->replay([
            'method' => 'GET',
            'path' => '/api/v1/invoices/'.$invoiceId,
            'headers' => $this->manifest['requests'][1]['headers'] ?? [],
        ]), 10);
    }

    /**
     * Runs the port of Rails' BillSubscriptionJob inline, under the same
     * frozen instant the capture billed at (rewindTime pinned CAPTURED_AT;
     * billing needs the period boundary).
     */
    protected function billInProcess(): void
    {
        $subscription = Subscription::query()
            ->where('external_id', $this->subscriptionExternalId())
            ->firstOrFail();

        $capturedAt = CarbonImmutable::getTestNow();
        CarbonImmutable::setTestNow(new CarbonImmutable($this->billingAt()));

        try {
            (new BillSubscriptionJob([$subscription], strtotime($this->billingAt()), 'subscription_periodic'))
                ->handle();
        } finally {
            CarbonImmutable::setTestNow($capturedAt);
        }
    }

    protected function mintedInvoiceId(): string
    {
        $invoiceId = Invoice::query()
            ->where('customer_id', function ($query): void {
                $query->select('id')
                    ->from('customers')
                    ->where('external_id', $this->customerExternalId())
                    ->limit(1);
            })
            ->value('id');

        if ($invoiceId === null) {
            static::fail('In-process billing did not mint an invoice for '.$this->customerExternalId().'.');
        }

        return $invoiceId;
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
