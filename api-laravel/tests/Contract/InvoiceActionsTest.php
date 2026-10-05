<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Contract test for the invoice_actions scenario, captured from the real
 * Rails API by scripts/contract/capture.sh invoice_actions.
 *
 * Covers the invoice lifecycle member actions: the one-off invoice create
 * (add-on fee with per-fee tax codes), show + PATCH payment_status on that
 * MINTED invoice (tokenized id), then on a SEEDED DRAFT subscription
 * invoice: PUT refresh (RefreshDraftService re-derives the billing
 * boundaries — the rebuilt invoice_subscription yields Rails' degenerate
 * same-day period and the 158c proration IS the contract), the
 * metadata-on-draft 405, PUT finalize (refresh inside, then the status
 * flip), show, payment_url with no provider (422 no_linked_payment_provider),
 * resend_email (403 premium_license_required — premium-gated in the OSS
 * capture stack; that envelope IS the contract, same precedent as invoice
 * preview), lose_dispute, POST void, the update-on-voided 405, retry on a
 * non-failed invoice (invalid_status) and the org-wide index.
 *
 * The one-off invoice's lago_id is minted by request #1, so requests #2/#3
 * carry the ONE_OFF_INVOICE_ID token in the manifest and this test
 * substitutes the id its own request #1 minted.
 *
 * PATCH payment_status syncs fee payment statuses through
 * Invoices::UpdateFeesPaymentStatusJob (perform_after_commit), which Rails'
 * :test adapter records but never runs — Queue::fake() mirrors that, so the
 * golden fees stay pending. Do not "fix" the divergence by running the job.
 */
class InvoiceActionsTest extends ContractCase
{
    protected string $scenario = 'invoice_actions';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        // The capture stack is the OSS Rails image — no license, so
        // resend_email answers the premium_license_required envelope (that
        // envelope IS the contract here, same precedent as invoice preview).
        config(['lago.license' => null]);
    }

    public function test_replays_the_captured_requests_against_the_goldens(): void
    {
        $this->runScenario();
    }

    protected function substituteRequestValues(array $request, int $oneBasedIndex, array $responses): array
    {
        $invoiceId = ($responses[1] ?? null)?->json('invoice.lago_id');

        if ($invoiceId !== null) {
            $request = $this->replaceTokens($request, ['ONE_OFF_INVOICE_ID' => $invoiceId]);
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
