<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Contract test for the credit_notes_lifecycle scenario, captured from the
 * real Rails API by scripts/contract/capture.sh credit_notes_lifecycle.
 *
 * The scenario billed a subscription IN-PROCESS (BillSubscriptionJob) BEFORE
 * dumping the seed state, so the finalized invoice rides fixture.sql with a
 * deterministic id and every manifest body can address it directly. The
 * test mirrors that in-process billing: it is NOT replayed here (it already
 * happened before the captured seed state) — the invoice is fixture data.
 *
 * Covers credit note create (items[] breakdown), estimate, index, show,
 * update (invalid refund_status envelope), void, show-after-void, and the
 * over-credit validation envelope.
 *
 * Requests #4-#7 address the credit note minted by request #1; its id is
 * minted per runtime, so the manifest carries the CREDIT_NOTE_ID token and
 * this test substitutes the id its own request #1 minted.
 */
class CreditNotesLifecycleTest extends ContractCase
{
    protected string $scenario = 'credit_notes_lifecycle';

    protected function setUp(): void
    {
        parent::setUp();

        // Rails' capture ran with the :test ActiveJob adapter — jobs were
        // recorded, never executed (invoice + credit note webhooks/PDFs).
        Queue::fake();

        // The scenario flipped the premium singleton on the Rails side
        // (License.instance_variable_set(:@premium, true)) — credit notes
        // are License.premium?-gated in BOTH runtimes
        // (App\Support\License::premium() reads config('lago.license')).
        // This is the replay's flip, NOT an app change: with it, the same
        // production credit-note pipeline runs on both sides.
        config(['lago.license' => 'contract-capture-license']);
    }

    public function test_replays_the_captured_requests_against_the_goldens(): void
    {
        $this->runScenario();
    }

    protected function substituteRequestValues(array $request, int $oneBasedIndex, array $responses): array
    {
        $creditNoteId = ($responses[1] ?? null)?->json('credit_note.lago_id');

        if ($creditNoteId !== null) {
            $request = $this->replaceTokens($request, ['CREDIT_NOTE_ID' => $creditNoteId]);
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
