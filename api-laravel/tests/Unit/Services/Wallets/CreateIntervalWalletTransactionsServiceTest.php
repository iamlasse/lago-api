<?php

declare(strict_types=1);

use App\Models\Wallet;
use App\Models\Customer;
use Illuminate\Support\Carbon;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Queue;
use App\Models\RecurringTransactionRule;
use App\Jobs\WalletTransactions\CreateJob;
use App\Services\Wallets\CreateIntervalWalletTransactionsService;

/**
 * Port of Rails'
 * spec/services/wallets/create_interval_wallet_transactions_service_spec.rb.
 */
uses()->group('ledger:svc:Wallets.CreateIntervalWalletTransactionsService');

beforeEach(function (): void {
    Carbon::setTestNow();
    Queue::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function intervalWallet(Customer $customer, string $createdAt, array $overrides = []): Wallet
{
    return Wallet::factory()->forCustomer($customer)->create(array_merge([
        'created_at' => Carbon::parse($createdAt),
        'credits_ongoing_balance' => 50,
        'paid_top_up_min_amount_cents' => 200_00,
    ], $overrides));
}

function intervalRule(Wallet $wallet, string $interval, string $createdAt, ?string $startedAt = null, array $overrides = []): RecurringTransactionRule
{
    return RecurringTransactionRule::factory()->forWallet($wallet)->create(array_merge([
        'trigger' => 'interval',
        'interval' => $interval,
        'created_at' => Carbon::parse($createdAt),
        'started_at' => $startedAt,
    ], $overrides));
}

/**
 * Asserts a WalletTransactions\CreateJob was enqueued for the rule with the
 * expected payload (Rails' expect_to_have_scheduled_wallet_transaction).
 */
function expectScheduledWalletTransaction(Wallet $wallet, RecurringTransactionRule $rule, array $attrs = []): void
{
    Queue::assertPushed(CreateJob::class, function (CreateJob $job) use ($wallet, $rule, $attrs): bool {
        $expected = array_merge([
            'wallet_id' => $wallet->id,
            'paid_credits' => moneyToF($rule->paid_credits),
            'granted_credits' => moneyToF($rule->granted_credits),
            'source' => 'interval',
            'invoice_requires_successful_payment' => false,
            'metadata' => $rule->transaction_metadata ?? [],
            'name' => $rule->transaction_name,
            'ignore_paid_top_up_limits' => false,
            'purchase_order_number' => null,
        ], $attrs);

        if ($job->organizationId !== (string) $wallet->organization_id) {
            return false;
        }

        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $job->params) || $job->params[$key] !== $value) {
                return false;
            }
        }

        return true;
    });
}

/** BigDecimal#to_s rendering for the decimal(30,5) columns ('10.00000' → '10.0'). */
function moneyToF(mixed $numeric): string
{
    return App\Support\MoneyMath::toF((string) $numeric);
}

function runIntervalService(): void
{
    CreateIntervalWalletTransactionsService::call();
}

it('enqueues a job on the weekly anniversary day', function (): void {
    $createdAt = '2021-02-20 00:00:00';
    $customer = Customer::factory()->create();
    $wallet = intervalWallet($customer, $createdAt);
    $rule = intervalRule($wallet, 'weekly', '2021-02-20 00:00:01');

    Carbon::setTestNow(Carbon::parse('2022-06-20')->subWeeks(1)->previous(Carbon::parse($createdAt)->format('l')));

    runIntervalService();

    expectScheduledWalletTransaction($wallet, $rule);
});

it('does not enqueue a job on other days (weekly)', function (): void {
    $createdAt = '2021-02-20 00:00:00';
    $customer = Customer::factory()->create();
    $wallet = intervalWallet($customer, $createdAt);
    intervalRule($wallet, 'weekly', '2021-02-20 00:00:01');

    $currentDate = Carbon::parse('2022-06-20')->subWeeks(1)->previous(Carbon::parse($createdAt)->format('l'));
    Carbon::setTestNow($currentDate->copy()->addDay());

    runIntervalService();

    Queue::assertNotPushed(CreateJob::class);
});

it('enqueues a job with a nil transaction_name when the rule has none', function (): void {
    $createdAt = '2021-02-20 00:00:00';
    $customer = Customer::factory()->create();
    $wallet = intervalWallet($customer, $createdAt);
    $rule = intervalRule($wallet, 'weekly', '2021-02-20 00:00:01', null, ['transaction_name' => null]);

    Carbon::setTestNow(Carbon::parse('2022-06-20')->subWeeks(1)->previous(Carbon::parse($createdAt)->format('l')));

    runIntervalService();

    expectScheduledWalletTransaction($wallet, $rule, ['name' => null]);
});

describe('started_at override', function (): void {
    it('does not enqueue a job one week after the creation date when started_at is later', function (): void {
        $createdAt = '2021-02-20 00:00:00';
        $customer = Customer::factory()->create();
        $wallet = intervalWallet($customer, $createdAt);
        intervalRule($wallet, 'weekly', '2021-02-20 00:00:01', '2022-06-20 00:00:00');

        Carbon::setTestNow(Carbon::parse('2022-06-20')->subWeeks(1)->previous(Carbon::parse($createdAt)->format('l')));

        runIntervalService();

        Queue::assertNotPushed(CreateJob::class);
    });

    it('enqueues a job one week after the started_at date', function (): void {
        $createdAt = '2021-02-20 00:00:00';
        $customer = Customer::factory()->create();
        $wallet = intervalWallet($customer, $createdAt);
        $rule = intervalRule($wallet, 'weekly', '2021-02-20 00:00:01', '2022-06-20 00:00:00');

        $currentDate = Carbon::parse('2022-06-20')->next(Carbon::parse($rule->started_at)->format('l'));
        Carbon::setTestNow($currentDate);

        runIntervalService();

        expectScheduledWalletTransaction($wallet, $rule);
    });

    it('does not enqueue a job before a future started_at sharing the same month day (monthly)', function (): void {
        $createdAt = '2021-02-20 00:00:00';
        $customer = Customer::factory()->create();
        $wallet = intervalWallet($customer, $createdAt);
        intervalRule($wallet, 'monthly', '2021-02-20 00:00:01', '2022-07-20 00:00:00');

        Carbon::setTestNow(Carbon::parse('2022-06-20'));

        runIntervalService();

        Queue::assertNotPushed(CreateJob::class);
    });
});

describe('target method', function (): void {
    function targetWeeklySetup(array $overrides = []): array
    {
        $createdAt = '2021-02-20 00:00:00';
        $customer = Customer::factory()->create();
        $wallet = intervalWallet($customer, $createdAt);
        $rule = intervalRule($wallet, 'weekly', '2021-02-20 00:00:01', null, array_merge([
            'method' => 'target',
            'target_ongoing_balance' => 200,
        ], $overrides));

        return [$wallet, $rule];
    }

    it('calls the job with the clamped gap and ignore_paid_top_up_limits', function (): void {
        [$wallet, $rule] = targetWeeklySetup();

        Carbon::setTestNow(Carbon::parse('2022-06-20')->subWeeks(1)->previous(Carbon::parse('2021-02-20 00:00:00')->format('l')));

        runIntervalService();

        // the gap is 150 but wallet has min amount set to 200
        expectScheduledWalletTransaction($wallet, $rule, [
            'paid_credits' => '200.0',
            'granted_credits' => '0.0',
            'ignore_paid_top_up_limits' => true,
        ]);
    });

    it('enqueues the raw gap as granted credits when grants_target_top_up is true', function (): void {
        [$wallet, $rule] = targetWeeklySetup(['grants_target_top_up' => true]);

        Carbon::setTestNow(Carbon::parse('2022-06-20')->subWeeks(1)->previous(Carbon::parse('2021-02-20 00:00:00')->format('l')));

        runIntervalService();

        expectScheduledWalletTransaction($wallet, $rule, [
            'paid_credits' => '0.0',
            'granted_credits' => '150.0',
            'ignore_paid_top_up_limits' => true,
        ]);
    });
});

describe('monthly', function (): void {
    it('enqueues a job on the monthly anniversary day', function (): void {
        $createdAt = '2021-02-20 00:00:00';
        $customer = Customer::factory()->create();
        $wallet = intervalWallet($customer, $createdAt);
        $rule = intervalRule($wallet, 'monthly', '2021-02-20 00:00:01');

        Carbon::setTestNow(Carbon::parse('2021-03-20'));

        runIntervalService();

        expectScheduledWalletTransaction($wallet, $rule);
    });

    it('does not enqueue a job on other days (monthly)', function (): void {
        $createdAt = '2021-02-20 00:00:00';
        $customer = Customer::factory()->create();
        $wallet = intervalWallet($customer, $createdAt);
        intervalRule($wallet, 'monthly', '2021-02-20 00:00:01');

        Carbon::setTestNow(Carbon::parse('2021-03-21'));

        runIntervalService();

        Queue::assertNotPushed(CreateJob::class);
    });

    it('enqueues a job on the last day of a short month for a 31st wallet', function (): void {
        $customer = Customer::factory()->create();
        $wallet = intervalWallet($customer, '2021-03-31 00:00:00');
        $rule = intervalRule($wallet, 'monthly', '2021-03-31 00:00:01');

        Carbon::setTestNow(Carbon::parse('2021-04-30'));

        runIntervalService();

        expectScheduledWalletTransaction($wallet, $rule);
    });

    it('honors started_at over created_at for monthly rules', function (): void {
        $customer = Customer::factory()->create();
        $wallet = intervalWallet($customer, '2025-03-31 00:00:00');
        intervalRule($wallet, 'monthly', '2025-03-31 00:00:01', '2025-04-15 00:00:00');

        Carbon::setTestNow(Carbon::parse('2025-04-30'));
        runIntervalService();
        Queue::assertNotPushed(CreateJob::class);

        Carbon::setTestNow(Carbon::parse('2025-05-15'));
        runIntervalService();

        Queue::assertPushed(CreateJob::class, 1);
    });
});

describe('quarterly', function (): void {
    function quarterlyScenario(string $createdAt, string $currentDate): array
    {
        $customer = Customer::factory()->create();
        $wallet = intervalWallet($customer, $createdAt);
        $rule = intervalRule($wallet, 'quarterly', Carbon::parse($createdAt)->addSecond()->toDateTimeString());

        return [$wallet, $rule, $currentDate];
    }

    it('enqueues a job on the quarterly anniversary day', function (): void {
        $createdAt = '2021-02-20 00:00:00';
        [$wallet, $rule] = quarterlyScenario($createdAt, '2021-05-20');
        Carbon::setTestNow(Carbon::parse('2021-05-20'));

        runIntervalService();

        expectScheduledWalletTransaction($wallet, $rule);
    });

    it('does not enqueue a job on other days (quarterly)', function (): void {
        [$wallet] = quarterlyScenario('2021-02-20 00:00:00', '2021-05-21');
        Carbon::setTestNow(Carbon::parse('2021-05-21'));

        runIntervalService();

        Queue::assertNotPushed(CreateJob::class);
    });

    it('enqueues a job every three months apart (March → September)', function (): void {
        [$wallet, $rule] = quarterlyScenario('2021-03-15 00:00:00', '2022-09-15');
        Carbon::setTestNow(Carbon::parse('2022-09-15'));

        runIntervalService();

        expectScheduledWalletTransaction($wallet, $rule);
    });

    it('enqueues a job on the last day of a short month for a 31st wallet', function (): void {
        [$wallet, $rule] = quarterlyScenario('2021-03-31 00:00:00', '2022-06-30');
        Carbon::setTestNow(Carbon::parse('2022-06-30'));

        runIntervalService();

        expectScheduledWalletTransaction($wallet, $rule);
    });
});

describe('yearly', function (): void {
    it('enqueues a job on the yearly anniversary day', function (): void {
        $customer = Customer::factory()->create();
        $wallet = intervalWallet($customer, '2021-02-20 00:00:00');
        $rule = intervalRule($wallet, 'yearly', '2021-02-20 00:00:01');

        Carbon::setTestNow(Carbon::parse('2022-02-20'));

        runIntervalService();

        expectScheduledWalletTransaction($wallet, $rule);
    });

    it('does not enqueue a job on other days (yearly)', function (): void {
        $customer = Customer::factory()->create();
        $wallet = intervalWallet($customer, '2021-02-20 00:00:00');
        intervalRule($wallet, 'yearly', '2021-02-20 00:00:01');

        Carbon::setTestNow(Carbon::parse('2022-02-21'));

        runIntervalService();

        Queue::assertNotPushed(CreateJob::class);
    });

    it('enqueues a job on february 28th when the wallet was created on february 29th of a leap year', function (): void {
        $customer = Customer::factory()->create();
        $wallet = intervalWallet($customer, '2020-02-29 00:00:00');
        $rule = intervalRule($wallet, 'yearly', '2020-02-29 00:00:01');

        Carbon::setTestNow(Carbon::parse('2022-02-28'));

        runIntervalService();

        expectScheduledWalletTransaction($wallet, $rule);
    });
});

describe('wallet creation day', function (): void {
    it('does not enqueue a job on the wallet creation day', function (): void {
        $customer = Customer::factory()->create();
        $wallet = intervalWallet($customer, '2021-02-20 00:00:00');
        intervalRule($wallet, 'monthly', '2021-02-20 00:00:01');

        Carbon::setTestNow(Carbon::parse('2021-02-20'));

        runIntervalService();

        Queue::assertNotPushed(CreateJob::class);
    });

    it('does not enqueue a job on the wallet creation day in the customer timezone', function (): void {
        $customer = Customer::factory()->create(['timezone' => 'Pacific/Noumea']);
        $wallet = intervalWallet($customer, '2021-02-20 00:00:00');
        intervalRule($wallet, 'monthly', '2021-02-20 00:00:01');

        Carbon::setTestNow(Carbon::parse('2021-02-20 10:00:00'));

        runIntervalService();

        Queue::assertNotPushed(CreateJob::class);
    });
});

describe('already applied today', function (): void {
    function appliedTodaySetup(?string $timezone = null, string $now = '2021-03-20 12:00:00'): Wallet
    {
        $customer = Customer::factory()->create(['timezone' => $timezone]);
        $wallet = intervalWallet($customer, '2021-02-20 00:00:00');
        intervalRule($wallet, 'monthly', '2021-02-20 00:00:01');

        WalletTransaction::factory()->create([
            'wallet_id' => $wallet->id,
            'organization_id' => $wallet->organization_id,
            'transaction_type' => App\Enums\WalletTransactionType::Inbound,
            'source' => App\Enums\WalletTransactionSource::Interval,
            'created_at' => Carbon::parse($now)->subHour(),
        ]);

        Carbon::setTestNow(Carbon::parse($now));

        return $wallet;
    }

    it('does not enqueue a job when transactions had already been created that day', function (): void {
        appliedTodaySetup();

        runIntervalService();

        Queue::assertNotPushed(CreateJob::class);
    });

    it('does not enqueue a job when the interval top-up already happened in the customer timezone', function (): void {
        appliedTodaySetup('Pacific/Noumea', '2021-03-20 22:00:00');

        runIntervalService();

        Queue::assertNotPushed(CreateJob::class);
    });
});

it('follows the rule invoice_requires_successful_payment configuration', function (): void {
    $createdAt = '2021-02-20 00:00:00';
    $customer = Customer::factory()->create();
    $wallet = intervalWallet($customer, $createdAt);
    $rule = intervalRule($wallet, 'weekly', '2021-02-20 00:00:01', null, ['invoice_requires_successful_payment' => true]);

    Carbon::setTestNow(Carbon::parse('2022-06-20')->subWeeks(1)->previous(Carbon::parse($createdAt)->format('l')));

    runIntervalService();

    expectScheduledWalletTransaction($wallet, $rule, ['invoice_requires_successful_payment' => true]);
});

it('enqueues the rule transaction metadata', function (): void {
    $createdAt = '2021-02-20 00:00:00';
    $customer = Customer::factory()->create();
    $wallet = intervalWallet($customer, $createdAt);
    $metadata = [['key' => 'valid_value', 'value' => 'also_valid']];
    $rule = intervalRule($wallet, 'weekly', '2021-02-20 00:00:01', null, ['transaction_metadata' => $metadata]);

    Carbon::setTestNow(Carbon::parse('2022-06-20')->subWeeks(1)->previous(Carbon::parse($createdAt)->format('l')));

    runIntervalService();

    expectScheduledWalletTransaction($wallet, $rule, ['metadata' => $metadata]);
});

it('enqueues the job with the rule transaction name', function (): void {
    $createdAt = '2021-02-20 00:00:00';
    $customer = Customer::factory()->create();
    $wallet = intervalWallet($customer, $createdAt);
    intervalRule($wallet, 'weekly', '2021-02-20 00:00:01', null, ['transaction_name' => 'Monthly Credits Refill']);

    Carbon::setTestNow(Carbon::parse('2022-06-20')->subWeeks(1)->previous(Carbon::parse($createdAt)->format('l')));

    runIntervalService();

    Queue::assertPushed(CreateJob::class, fn (CreateJob $job) => $job->params['name'] === 'Monthly Credits Refill');
});

it('enqueues the job with the rule purchase order number', function (): void {
    $createdAt = '2021-02-20 00:00:00';
    $customer = Customer::factory()->create();
    $wallet = intervalWallet($customer, $createdAt);
    $rule = intervalRule($wallet, 'weekly', '2021-02-20 00:00:01', null, ['purchase_order_number' => 'PO-RULE-123']);

    Carbon::setTestNow(Carbon::parse('2022-06-20')->subWeeks(1)->previous(Carbon::parse($createdAt)->format('l')));

    runIntervalService();

    expectScheduledWalletTransaction($wallet, $rule, ['purchase_order_number' => 'PO-RULE-123']);
});

it('falls back to the wallet purchase order number when the rule one is blank', function (): void {
    $createdAt = '2021-02-20 00:00:00';
    $customer = Customer::factory()->create();
    $wallet = intervalWallet($customer, $createdAt, ['purchase_order_number' => 'PO-WALLET-123']);
    $rule = intervalRule($wallet, 'weekly', '2021-02-20 00:00:01', null, ['purchase_order_number' => null]);

    Carbon::setTestNow(Carbon::parse('2022-06-20')->subWeeks(1)->previous(Carbon::parse($createdAt)->format('l')));

    runIntervalService();

    expectScheduledWalletTransaction($wallet, $rule, ['purchase_order_number' => 'PO-WALLET-123']);
});

it('does not expire already-run rules', function (): void {
    $createdAt = '2021-02-20 00:00:00';
    $customer = Customer::factory()->create();
    $wallet = intervalWallet($customer, $createdAt);
    intervalRule($wallet, 'weekly', '2021-02-20 00:00:01', null, [
        'expiration_at' => Carbon::parse($createdAt)->addHours(2),
    ]);

    Carbon::setTestNow(Carbon::parse($createdAt)->addWeeks(2));

    runIntervalService();

    Queue::assertNotPushed(CreateJob::class);
});

describe('zero credits', function (): void {
    it('does not enqueue a job when both paid and granted credits are zero on a target rule', function (): void {
        $createdAt = '2021-02-20 00:00:00';
        $customer = Customer::factory()->create();
        $wallet = intervalWallet($customer, $createdAt, ['credits_ongoing_balance' => 500]);
        intervalRule($wallet, 'weekly', $createdAt, null, [
            'method' => 'target',
            'target_ongoing_balance' => 500,
            'granted_credits' => 0,
        ]);

        Carbon::setTestNow(Carbon::parse($createdAt)->addWeeks(2));

        runIntervalService();

        Queue::assertNotPushed(CreateJob::class);
    });

    it('enqueues a job when only the paid credits is zero', function (): void {
        $createdAt = '2021-02-20 00:00:00';
        $customer = Customer::factory()->create();
        $wallet = intervalWallet($customer, $createdAt, ['credits_ongoing_balance' => 1000]);
        $rule = intervalRule($wallet, 'weekly', $createdAt, null, [
            'method' => 'fixed',
            'target_ongoing_balance' => 500,
            'granted_credits' => 100,
        ]);

        Carbon::setTestNow(Carbon::parse($createdAt)->addWeeks(2));

        runIntervalService();

        expectScheduledWalletTransaction($wallet, $rule);
    });

    it('enqueues a job when only the granted credits is zero', function (): void {
        $createdAt = '2021-02-20 00:00:00';
        $customer = Customer::factory()->create();
        $wallet = intervalWallet($customer, $createdAt, ['credits_ongoing_balance' => 0, 'paid_top_up_min_amount_cents' => null]);
        $rule = intervalRule($wallet, 'weekly', $createdAt, null, [
            'method' => 'target',
            'paid_credits' => 100,
            'target_ongoing_balance' => 500,
        ]);

        Carbon::setTestNow(Carbon::parse($createdAt)->addWeeks(2));

        runIntervalService();

        expectScheduledWalletTransaction($wallet, $rule, [
            'paid_credits' => '500.0',
            'granted_credits' => '0.0',
            'ignore_paid_top_up_limits' => true,
        ]);
    });

    it('enqueues a job when both credits are non-zero', function (): void {
        $createdAt = '2021-02-20 00:00:00';
        $customer = Customer::factory()->create();
        $wallet = intervalWallet($customer, $createdAt, ['credits_ongoing_balance' => 0, 'paid_top_up_min_amount_cents' => null]);
        $rule = intervalRule($wallet, 'weekly', $createdAt, null, [
            'method' => 'target',
            'paid_credits' => 100,
            'granted_credits' => 50,
            'target_ongoing_balance' => 500,
        ]);

        Carbon::setTestNow(Carbon::parse($createdAt)->addWeeks(2));

        runIntervalService();

        expectScheduledWalletTransaction($wallet, $rule, [
            'paid_credits' => '500.0',
            'granted_credits' => '0.0',
            'ignore_paid_top_up_limits' => true,
        ]);
    });
});

describe('invoice custom sections', function (): void {
    function weeklyRuleOnAnniversary(array $overrides = []): array
    {
        $createdAt = '2021-02-20 00:00:00';
        $customer = Customer::factory()->create();
        $wallet = intervalWallet($customer, $createdAt);
        $rule = intervalRule($wallet, 'weekly', '2021-02-20 00:00:01', null, $overrides);

        Carbon::setTestNow(Carbon::parse('2022-06-20')->subWeeks(1)->previous(Carbon::parse($createdAt)->format('l')));

        return [$wallet, $rule];
    }

    it('forwards invoice_custom_section params to the job', function (): void {
        [$wallet, $rule] = weeklyRuleOnAnniversary();

        $section = App\Models\InvoiceCustomSection::factory()->create(['organization_id' => $wallet->organization_id]);

        App\Models\RecurringTransactionRuleAppliedInvoiceCustomSection::query()->create([
            'organization_id' => $wallet->organization_id,
            'recurring_transaction_rule_id' => $rule->id,
            'invoice_custom_section_id' => $section->id,
        ]);

        runIntervalService();

        Queue::assertPushed(CreateJob::class, fn (CreateJob $job) => $job->params['invoice_custom_section'] === [
            'skip_invoice_custom_sections' => false,
            'invoice_custom_section_ids' => [$section->id],
        ]);
    });

    it('forwards the skip flag without fallback to other sections', function (): void {
        [$wallet] = weeklyRuleOnAnniversary(['skip_invoice_custom_sections' => true]);

        runIntervalService();

        Queue::assertPushed(CreateJob::class, fn (CreateJob $job) => $job->params['invoice_custom_section'] === [
            'skip_invoice_custom_sections' => true,
            'invoice_custom_section_ids' => [],
        ]);
    });
});
