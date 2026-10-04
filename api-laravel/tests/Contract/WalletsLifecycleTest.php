<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Contract test for the wallets_lifecycle scenario, captured from the real
 * Rails API by scripts/contract/capture.sh wallets_lifecycle.
 *
 * Covers the REST wallets surface: wallet create (paid + granted credits),
# the customer-filtered index, show, update, wallet_transactions create
 * (granted → settled immediately; paid → pending — the settlement job is
 * recorded, never run, under the :test adapter), a pool-wide void, show of
 * a minted transaction, the wallet-scoped index + status filters,
 * terminate, and show-after-terminate.
 *
 * Request #11 shows the transaction minted by request #5; its id is minted
 * per runtime, so the manifest carries the GRANTED_TRANSACTION_ID token and
 * this test substitutes the id its own request #5 minted.
 */
class WalletsLifecycleTest extends ContractCase
{
    protected string $scenario = 'wallets_lifecycle';

    protected function setUp(): void
    {
        parent::setUp();

        // Rails' capture ran with the :test ActiveJob adapter — jobs were
        // recorded, never executed (wallet + transaction webhooks, and the
        // BillPaidCreditJob that would settle the paid top-up).
        Queue::fake();
    }

    public function test_replays_the_captured_requests_against_the_goldens(): void
    {
        $this->runScenario();
    }

    protected function substituteRequestValues(array $request, int $oneBasedIndex, array $responses): array
    {
        $grantedTxId = ($responses[5] ?? null)?->json('wallet_transactions.0.lago_id');

        if ($grantedTxId !== null) {
            $request = $this->replaceTokens($request, ['GRANTED_TRANSACTION_ID' => $grantedTxId]);
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
