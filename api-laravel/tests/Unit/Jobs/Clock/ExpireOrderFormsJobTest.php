<?php

declare(strict_types=1);

use App\Models\Quote;
use App\Models\Customer;
use App\Models\OrderForm;
use App\Models\Organization;
use App\Models\QuoteVersion;
use Illuminate\Support\Facades\Queue;
use App\Jobs\Clock\ExpireOrderFormsJob;
use Database\Factories\OrderFormFactory;
use App\Jobs\OrderForms\ExpireOrderFormJob;

uses()->group('ledger:job:Clock.ExpireOrderFormsJob');

/**
 * Port of Rails' spec/jobs/clock/expire_order_forms_job_spec.rb — the hourly
 * sweep fans out one OrderForms::ExpireOrderFormJob per expirable order form
 * (generated, expires_at past, not voided/expired).
 */
function expireClockFormFactory(array $attributes = []): OrderFormFactory
{
    $organization = Organization::factory()->create(['feature_flags' => ['order_forms']]);
    config(['lago.license' => 'premium-license-token']);
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $quote = Quote::factory()->forCustomer($customer)->create();
    $version = QuoteVersion::factory()->forQuote($quote)->approved()->withOneOffBillingItems()->create();

    return OrderForm::factory()
        ->forCustomer($customer)
        ->forQuoteVersion($version)
        ->state(fn (): array => $attributes);
}

it('enqueues expire jobs only for generated order forms past expires_at', function (): void {
    Queue::fake();

    $expiredOrderForm = expireClockFormFactory(['expires_at' => now()->subDay()])->create();
    $futureOrderForm = expireClockFormFactory(['expires_at' => now()->addDay()])->create();
    $noExpiryOrderForm = expireClockFormFactory(['expires_at' => null])->create();
    $alreadyExpiredOrderForm = expireClockFormFactory()->expired()->create();
    $voidedOrderForm = expireClockFormFactory()->voided()->create();

    (new ExpireOrderFormsJob)->handle();

    Queue::assertPushed(ExpireOrderFormJob::class, 1);

    // The single enqueued job belongs to the one expirable form.
    $expirable = OrderForm::query()->expirable()->get();
    expect($expirable)->toHaveCount(1)
        ->and($expirable->first()->id)->toBe($expiredOrderForm->id)
        ->and($futureOrderForm->refresh()->status)->toBe('generated')
        ->and($noExpiryOrderForm->refresh()->status)->toBe('generated')
        ->and($alreadyExpiredOrderForm->status)->toBe('expired')
        ->and($voidedOrderForm->status)->toBe('voided');
});
