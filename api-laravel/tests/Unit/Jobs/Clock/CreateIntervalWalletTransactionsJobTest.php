<?php

declare(strict_types=1);

use App\Models\Wallet;
use App\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use App\Models\RecurringTransactionRule;
use App\Jobs\WalletTransactions\CreateJob;
use App\Jobs\Clock\CreateIntervalWalletTransactionsJob;

/**
 * Port of Rails' spec/jobs/clock/create_interval_wallet_transactions_job_spec.rb
 * — Rails stubs Wallets::CreateIntervalWalletTransactionsService and asserts
 * #perform delegates to it; the port runs the service against a due rule and
 * asserts the top-up job was enqueued (an alias stub cannot be used: the
 * service class is already loaded in the test process).
 */
uses()->group('ledger:job:Clock.CreateIntervalWalletTransactionsJob');

it('enqueues the interval top-ups due today', function (): void {
    Carbon::setTestNow();
    Queue::fake();

    $customer = Customer::factory()->create();
    $wallet = Wallet::factory()->forCustomer($customer)->create([
        'created_at' => Carbon::parse('2021-02-20 00:00:00'),
    ]);
    RecurringTransactionRule::factory()->forWallet($wallet)->create([
        'trigger' => 'interval',
        'interval' => 'monthly',
        'created_at' => Carbon::parse('2021-02-20 00:00:01'),
    ]);

    Carbon::setTestNow(Carbon::parse('2021-03-20'));

    (new CreateIntervalWalletTransactionsJob)->handle();

    Queue::assertPushed(CreateJob::class, 1);

    Carbon::setTestNow();
});
