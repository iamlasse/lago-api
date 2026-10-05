<?php

declare(strict_types=1);

use App\Models\Quote;
use App\Models\Customer;
use App\Models\OrderForm;
use App\Models\Organization;
use App\Models\QuoteVersion;
use App\Services\OrderForms\ExpireService;
use App\Jobs\OrderForms\ExpireOrderFormJob;

uses()->group('ledger:job:OrderForms.ExpireOrderFormJob');

/**
 * Port of Rails' spec/jobs/order_forms/expire_order_form_job_spec.rb — the
 * per-form job delegates to OrderForms::ExpireService and swallows the lock
 * failure (the clock sweep retries on the next run).
 */
it('expires the order form through ExpireService', function (): void {
    config(['lago.license' => 'premium-license-token']);
    $organization = Organization::factory()->create(['feature_flags' => ['order_forms']]);
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $quote = Quote::factory()->forCustomer($customer)->create();
    $version = QuoteVersion::factory()->forQuote($quote)->approved()->withOneOffBillingItems()->create();
    $orderForm = OrderForm::factory()
        ->forCustomer($customer)
        ->forQuoteVersion($version)
        ->create(['expires_at' => now()->subDay()]);

    (new ExpireOrderFormJob($orderForm))->handle();

    expect($orderForm->refresh()->status)->toBe('expired')
        ->and($orderForm->void_reason)->toBe('expired')
        ->and($version->refresh()->status)->toBe('voided')
        ->and($version->void_reason)->toBe('cascade_of_expired');
});
