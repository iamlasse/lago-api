<?php

declare(strict_types=1);

require_once __DIR__.'/CrudServicesTest.php';

use App\Jobs\SendWebhookJob;
use App\Models\PaymentRequest;
use Illuminate\Support\Facades\Queue;
use App\Models\DunningCampaignThreshold;
use App\Jobs\DunningCampaigns\ProcessAttemptJob;
use App\Services\DunningCampaigns\ProcessAttemptService;
use App\Services\DunningCampaigns\ProcessCustomerService;

/**
 * Ports of Rails' spec/services/dunning_campaigns/{process_customer,
 * process_attempt}_service_spec.rb. Ledger rows: svc:dunning_campaigns:
 * process_customer / process_attempt.
 */
beforeEach(function (): void {
    Queue::fake();
    config(['lago.license' => 'premium-license-token']);
});

afterEach(function (): void {
    config(['lago.license' => null]);
});

function dunningCustomerWithOverdueInvoice(
    App\Models\Organization $organization,
    App\Models\DunningCampaign $campaign,
    array $customerAttributes = [],
    int $amountCents = 1000,
    string $currency = 'EUR',
): App\Models\Customer {
    $customer = App\Models\Customer::factory()->forOrganization($organization)->create(array_merge([
        'applied_dunning_campaign_id' => $campaign->id,
    ], $customerAttributes));

    App\Models\Invoice::factory()->for($customer, 'customer')->for($organization, 'organization')
        ->create([
            'currency' => $currency,
            'total_amount_cents' => $amountCents,
            'payment_overdue' => true,
            'ready_for_payment_processing' => true,
        ]);

    return $customer;
}

it('increments the per-currency attempts and dispatches the attempt job', function (): void {
    $organization = dunningOrganization();
    $campaign = App\Models\DunningCampaign::factory()->forOrganization($organization)->create(['max_attempts' => 3]);
    $threshold = DunningCampaignThreshold::factory()->forCampaign($campaign)->forCurrency('EUR', 500)->create();

    $customer = dunningCustomerWithOverdueInvoice($organization, $campaign);

    $result = ProcessCustomerService::call(customer: $customer);

    expect($result->success())->toBeTrue();

    $customer = $customer->refresh();

    expect($customer->dunning_currency_attempts)->toBe(['EUR' => 1])
        ->and($customer->last_dunning_campaign_attempt_at)->not->toBeNull();

    Queue::assertPushed(ProcessAttemptJob::class, fn (ProcessAttemptJob $job): bool => $job->customerId === $customer->id
        && $job->dunningCampaignThresholdId === $threshold->id);
});

it('skips customers below the threshold amount', function (): void {
    $organization = dunningOrganization();
    $campaign = App\Models\DunningCampaign::factory()->forOrganization($organization)->create();
    DunningCampaignThreshold::factory()->forCampaign($campaign)->forCurrency('EUR', 5000)->create();

    $customer = dunningCustomerWithOverdueInvoice($organization, $campaign, amountCents: 100);

    ProcessCustomerService::call(customer: $customer);

    expect($customer->refresh()->dunning_currency_attempts)->toBe([]);
    Queue::assertNotPushed(ProcessAttemptJob::class);
});

it('skips excluded customers and customers without a campaign', function (): void {
    $organization = dunningOrganization();
    $campaign = App\Models\DunningCampaign::factory()->forOrganization($organization)->create();
    DunningCampaignThreshold::factory()->forCampaign($campaign)->forCurrency('EUR', 500)->create();

    $excluded = dunningCustomerWithOverdueInvoice($organization, $campaign, ['exclude_from_dunning_campaign' => true]);
    ProcessCustomerService::call(customer: $excluded);
    expect($excluded->refresh()->dunning_currency_attempts)->toBe([]);

    $unattached = App\Models\Customer::factory()->forOrganization($organization)->create();
    ProcessCustomerService::call(customer: $unattached);
    expect($unattached->refresh()->dunning_currency_attempts)->toBe([]);

    Queue::assertNotPushed(ProcessAttemptJob::class);
});

it('honors the days_between_attempts spacing', function (): void {
    $organization = dunningOrganization();
    $campaign = App\Models\DunningCampaign::factory()->forOrganization($organization)
        ->create(['days_between_attempts' => 7, 'max_attempts' => 3]);
    DunningCampaignThreshold::factory()->forCampaign($campaign)->forCurrency('EUR', 500)->create();

    $customer = dunningCustomerWithOverdueInvoice($organization, $campaign, [
        'dunning_currency_attempts' => ['EUR' => 1],
        'last_dunning_campaign_attempt_at' => now()->subDays(1),
    ]);

    ProcessCustomerService::call(customer: $customer);

    // 1 day ago + 7 days spacing → not yet due.
    expect($customer->refresh()->dunning_currency_attempts)->toBe(['EUR' => 1]);
    Queue::assertNotPushed(ProcessAttemptJob::class);

    $dueCustomer = dunningCustomerWithOverdueInvoice($organization, $campaign, [
        'dunning_currency_attempts' => ['EUR' => 1],
        'last_dunning_campaign_attempt_at' => now()->subDays(8),
    ]);

    ProcessCustomerService::call(customer: $dueCustomer);

    expect($dueCustomer->refresh()->dunning_currency_attempts)->toBe(['EUR' => 2]);
});

it('sends dunning_campaign.finished when every dunned currency reached max attempts', function (): void {
    $organization = dunningOrganization();
    $campaign = App\Models\DunningCampaign::factory()->forOrganization($organization)
        ->create(['max_attempts' => 1, 'code' => 'final_round']);
    DunningCampaignThreshold::factory()->forCampaign($campaign)->forCurrency('EUR', 500)->create();

    $customer = dunningCustomerWithOverdueInvoice($organization, $campaign);

    ProcessCustomerService::call(customer: $customer);

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->webhookType === 'dunning_campaign.finished'
        && $job->options['dunning_campaign_code'] === 'final_round');
});

it('does not exceed max attempts per currency', function (): void {
    $organization = dunningOrganization();
    $campaign = App\Models\DunningCampaign::factory()->forOrganization($organization)
        ->create(['max_attempts' => 1]);
    DunningCampaignThreshold::factory()->forCampaign($campaign)->forCurrency('EUR', 500)->create();

    $customer = dunningCustomerWithOverdueInvoice($organization, $campaign, [
        'dunning_currency_attempts' => ['EUR' => 1],
    ]);

    ProcessCustomerService::call(customer: $customer);

    expect($customer->refresh()->dunning_currency_attempts)->toBe(['EUR' => 1]);
    Queue::assertNotPushed(ProcessAttemptJob::class);
});

// -- ProcessAttemptService --------------------------------------------------

it('creates a dunning payment request over the overdue invoices', function (): void {
    $organization = dunningOrganization();
    $campaign = App\Models\DunningCampaign::factory()->forOrganization($organization)->create();
    $threshold = DunningCampaignThreshold::factory()->forCampaign($campaign)->forCurrency('EUR', 500)->create();

    $customer = dunningCustomerWithOverdueInvoice($organization, $campaign, amountCents: 1000);
    $invoice = $customer->invoices()->first();
    $billingEntity = App\Models\BillingEntity::query()->find($invoice->billing_entity_id);

    $result = ProcessAttemptService::call(
        customer: $customer,
        dunningCampaignThreshold: $threshold,
        billingEntity: $billingEntity,
    );

    expect($result->success())->toBeTrue()
        ->and($result->customer->id)->toBe($customer->id);

    $paymentRequest = $result->payment_request;

    expect($paymentRequest)->toBeInstanceOf(PaymentRequest::class)
        ->and($paymentRequest->dunning_campaign_id)->toBe($campaign->id)
        ->and($paymentRequest->invoices->pluck('id')->all())->toBe([$invoice->id])
        ->and($paymentRequest->amount_cents)->toBe(1000);
});

it('skips the attempt when the overdue sum is below the threshold', function (): void {
    $organization = dunningOrganization();
    $campaign = App\Models\DunningCampaign::factory()->forOrganization($organization)->create();
    $threshold = DunningCampaignThreshold::factory()->forCampaign($campaign)->forCurrency('EUR', 5000)->create();

    $customer = dunningCustomerWithOverdueInvoice($organization, $campaign, amountCents: 100);
    $invoice = $customer->invoices()->first();
    $billingEntity = App\Models\BillingEntity::query()->find($invoice->billing_entity_id);

    $result = ProcessAttemptService::call(
        customer: $customer,
        dunningCampaignThreshold: $threshold,
        billingEntity: $billingEntity,
    );

    expect($result->success())->toBeTrue()
        ->and($result->payment_request)->toBeNull()
        ->and(PaymentRequest::query()->count())->toBe(0);
});

it('skips the attempt when the campaign no longer applies to the customer', function (): void {
    $organization = dunningOrganization();

    // The campaign applies through a DIFFERENT billing entity than the
    // customer's — Rails' applicable_dunning_campaign? answers false.
    $campaign = App\Models\DunningCampaign::factory()->forOrganization($organization)->create();
    $threshold = DunningCampaignThreshold::factory()->forCampaign($campaign)->forCurrency('EUR', 500)->create();

    $customer = dunningCustomerWithOverdueInvoice($organization, $campaign, [
        'applied_dunning_campaign_id' => null,
    ]);
    $invoice = $customer->invoices()->first();
    $billingEntity = App\Models\BillingEntity::query()->find($invoice->billing_entity_id);

    $result = ProcessAttemptService::call(
        customer: $customer,
        dunningCampaignThreshold: $threshold,
        billingEntity: $billingEntity,
    );

    expect($result->success())->toBeTrue()
        ->and($result->payment_request)->toBeNull()
        ->and(PaymentRequest::query()->count())->toBe(0);
});
