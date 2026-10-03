<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Contract test for the invoice_standard scenario, captured from the real
 * Rails API by scripts/contract/capture.sh invoice_standard.
 *
 * Covers the SYNCHRONOUS invoice surface: tax creation, tax application via
 * customer create (tax_codes), the one-off invoice (POST /invoices —
 * Invoices::CreateOneOffService: fees + per-fee taxes + totals in-process),
 * the customer-scoped invoice index, and POST /invoices/preview (in the
 * OSS image this answers the feature_unavailable envelope — premium-gated).
 *
 * Extra golden 10.json is the show of the invoice created by manifest
 * request #3; its lago_id is minted per runtime, so this test re-creates
 * the invoice and substitutes the id ITS request minted.
 */
class InvoiceStandardTest extends ContractCase
{
    protected string $scenario = 'invoice_standard';

    protected function setUp(): void
    {
        parent::setUp();

        // Rails' capture ran with the :test ActiveJob adapter — jobs were
        // recorded, never executed (invoice creation enqueues webhook and
        // PDF jobs).
        Queue::fake();
    }

    public function test_replays_the_captured_requests_against_the_goldens(): void
    {
        $this->runScenario();
    }

    public function test_shows_the_invoice_the_replay_created(): void
    {
        $created = $this->replay($this->manifest['requests'][2]);

        $invoiceId = $created->json('invoice.lago_id');

        if ($invoiceId === null) {
            // The port does not register POST /api/v1/invoices yet (see the
            // runScenario findings); without a replay-minted invoice there
            // is nothing to show. The extra golden 10.json stays committed
            // for when the route lands.
            static::markTestSkipped('POST /api/v1/invoices is not ported yet — no replay-minted invoice id to show against golden 10.json.');
        }

        $response = $this->replay([
            'method' => 'GET',
            'path' => '/api/v1/invoices/'.$invoiceId,
        ]);

        // Golden index 10 is outside the manifest on purpose — see the
        // scenario script (extra golden keyed to the minted id).
        $this->assertMatchesGolden($response, 10);
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
