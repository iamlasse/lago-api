<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Models\AddOn;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\BillingEntity;
use App\Models\BillableMetric;
use App\Models\Integrations\XeroIntegration;
use App\Models\IntegrationMappings\XeroMapping;
use App\Models\Integrations\NetsuiteIntegration;
use App\Models\IntegrationCustomers\XeroCustomer;
use App\Models\IntegrationMappings\NetsuiteMapping;
use App\Models\IntegrationCustomers\NetsuiteCustomer;
use App\Services\Integrations\Aggregator\BasePayload\Failure;
use App\Services\Integrations\Aggregator\Invoices\Payloads\Xero;
use App\Models\IntegrationCollectionMappings\XeroCollectionMapping;
use App\Services\Integrations\Aggregator\Invoices\Payloads\Factory;
use App\Services\Integrations\Aggregator\Invoices\Payloads\Netsuite;
use App\Models\IntegrationCollectionMappings\NetsuiteCollectionMapping;

/**
 * The item_code resolution pinned end-to-end through the payload builders —
 * the mapping lookups the Xero/Netsuite slice documented as "always null"
 * before the mappings slice. Rails sources: integrations/aggregator/
 * base_payload.rb#lookup_mapping / lookup_collection_mapping / fallback_item.
 */
function payloadFixture(string $integrationClass): array
{
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'currency' => 'EUR',
        'taxes_amount_cents' => 0,
        'coupons_amount_cents' => 0,
        'prepaid_credit_amount_cents' => 0,
        'progressive_billing_credit_amount_cents' => 0,
        'credit_notes_amount_cents' => 0,
    ]);
    $integration = $integrationClass::factory()->forOrganization($organization)->create();

    $integrationCustomerClass = $integration instanceof XeroIntegration ? XeroCustomer::class : NetsuiteCustomer::class;
    $integrationCustomer = $integrationCustomerClass::factory()
        ->forIntegration($integration)
        ->forCustomer($customer)
        ->create();

    return [$organization, $customer, $invoice, $integration, $integrationCustomer];
}

function chargeFeeFixture(Organization $organization, Customer $customer, Invoice $invoice): Fee
{
    $plan = App\Models\Plan::factory()->create(['organization_id' => $organization->id]);
    $metric = BillableMetric::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'api',
        'name' => 'API',
    ]);
    $charge = App\Models\Charge::factory()->create([
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'properties' => ['amount' => '1'],
    ]);
    $subscription = App\Models\Subscription::factory()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
    ]);

    return Fee::factory()->chargeFee()->create([
        'invoice_id' => $invoice->id,
        'subscription_id' => $subscription->id,
        'organization_id' => $organization->id,
        'billing_entity_id' => $customer->billing_entity_id,
        'charge_id' => $charge->id,
        'invoiceable_type' => 'Charge',
        'invoiceable_id' => $charge->id,
        'amount_cents' => 100,
        'precise_amount_cents' => '100',
        'amount_currency' => 'EUR',
        'unit_amount_cents' => 100,
        'precise_unit_amount' => '1',
        'units' => '1',
        'taxes_amount_cents' => 0,
        'taxes_precise_amount_cents' => '0',
        'taxes_rate' => 0,
    ]);
}

function subscriptionFeeFixture(Organization $organization, Customer $customer, Invoice $invoice): Fee
{
    $plan = App\Models\Plan::factory()->create(['organization_id' => $organization->id]);
    $subscription = App\Models\Subscription::factory()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
    ]);

    return Fee::factory()->subscriptionFee()->create([
        'invoice_id' => $invoice->id,
        'subscription_id' => $subscription->id,
        'organization_id' => $organization->id,
        'billing_entity_id' => $customer->billing_entity_id,
        'amount_cents' => 1000,
        'precise_amount_cents' => '1000',
        'amount_currency' => 'EUR',
        'units' => '1',
        'taxes_amount_cents' => 0,
        'taxes_precise_amount_cents' => '0',
        'taxes_rate' => 0,
    ]);
}

it('resolves the xero item_code through the billable metric mapping end-to-end', function (): void {
    [, , $invoice, $integration, $integrationCustomer] = payloadFixture(XeroIntegration::class);
    $fee = chargeFeeFixture($invoice->organization, $invoice->customer, $invoice);

    // No mapping yet: the invalid_mapping failure like Rails.
    $payload = new Xero($integrationCustomer, $invoice);

    expect(fn () => $payload->body())->toThrow(Failure::class, 'invalid_mapping');

    $metric = $fee->charge->billableMetric;

    XeroMapping::factory()->forIntegration($integration)->forMappable('BillableMetric', $metric)->create([
        'organization_id' => $integration->organization_id,
    ]);

    $body = (new Xero($integrationCustomer, $invoice))->body();

    expect($body[0]['fees'])->toHaveCount(1)
        ->and($body[0]['fees'][0]['item_code'])->toBe('xero-123')
        ->and($body[0]['fees'][0]['account_code'])->toBe('xero-code-1');
});

it('resolves the netsuite item line through the billable metric mapping end-to-end', function (): void {
    [, , $invoice, $integration, $integrationCustomer] = payloadFixture(NetsuiteIntegration::class);
    $fee = chargeFeeFixture($invoice->organization, $invoice->customer, $invoice);

    NetsuiteMapping::factory()->forIntegration($integration)->forMappable('BillableMetric', $fee->charge->billableMetric)->create([
        'organization_id' => $integration->organization_id,
    ]);

    $body = (new Netsuite($integrationCustomer, $invoice))->body();

    $line = $body['lines'][0]['lineItems'][0];

    expect($line['item'])->toBe('netsuite-123')
        ->and($line['account'])->toBe('netsuite-code-1')
        ->and($line['description'])->toBe('API');
});

it('prefers the billing entity mapping over the organization one', function (): void {
    [$organization, $customer, $invoice, $integration, $integrationCustomer] = payloadFixture(XeroIntegration::class);
    $fee = subscriptionFeeFixture($organization, $customer, $invoice);

    $billingEntity = BillingEntity::factory()->forOrganization($organization)->create();
    $customer->billing_entity_id = $billingEntity->id;
    $customer->save();

    $payload = new Xero($integrationCustomer, $invoice);

    // Both scopes configured: the billing entity mapping wins.
    XeroCollectionMapping::factory()->withMappingType('subscription_fee')->forIntegration($integration)->create([
        'organization_id' => $organization->id,
        'settings' => ['external_id' => 'org-level'],
    ]);
    XeroCollectionMapping::factory()->withMappingType('subscription_fee')->forIntegration($integration)->create([
        'organization_id' => $organization->id,
        'billing_entity_id' => $customer->billing_entity_id,
        'settings' => ['external_id' => 'entity-level'],
    ]);

    expect($payload->mappedItem($fee)->external_id)->toBe('entity-level');

    // Only the org-level one: it answers.
    XeroCollectionMapping::query()->delete();
    XeroCollectionMapping::factory()->withMappingType('subscription_fee')->forIntegration($integration)->create([
        'organization_id' => $organization->id,
        'settings' => ['external_id' => 'org-level'],
    ]);

    expect($payload->mappedItem($fee)->external_id)->toBe('org-level');
});

it('falls back to the fallback_item mapping when no kind mapping exists', function (): void {
    [$organization, $customer, $invoice, $integration, $integrationCustomer] = payloadFixture(XeroIntegration::class);
    $fee = subscriptionFeeFixture($organization, $customer, $invoice);

    XeroCollectionMapping::factory()->withMappingType('fallback_item')->forIntegration($integration)->create([
        'organization_id' => $organization->id,
        'settings' => ['external_id' => 'fallback'],
    ]);

    $payload = new Xero($integrationCustomer, $invoice);

    // lookup_mapping / lookup_collection_mapping chain into the fallback item.
    expect($payload->mappedItem($fee)->external_id)->toBe('fallback');

    // An add-on fee with no mapping resolves through the fallback too.
    $addOn = AddOn::factory()->for($organization, 'organization')->create();
    $addOnFee = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'subscription_id' => $fee->subscription_id,
        'organization_id' => $organization->id,
        'billing_entity_id' => $customer->billing_entity_id,
        'add_on_id' => $addOn->id,
        'fee_type' => App\Enums\FeeType::AddOn,
        'amount_cents' => 100,
        'precise_amount_cents' => '100',
        'amount_currency' => 'EUR',
        'units' => '1',
        'taxes_amount_cents' => 0,
        'taxes_precise_amount_cents' => '0',
        'taxes_rate' => 0,
    ]);

    expect($payload->mappedItem($addOnFee)->external_id)->toBe('fallback');
});

it('maps the netsuite invoice currency through the currencies collection mapping', function (): void {
    [$organization, $customer, $invoice, $integration, $integrationCustomer] = payloadFixture(NetsuiteIntegration::class);
    $fee = chargeFeeFixture($organization, $customer, $invoice);

    // The fee line itself needs its mapping for body() to build.
    NetsuiteMapping::factory()->forIntegration($integration)->forMappable('BillableMetric', $fee->charge->billableMetric)->create([
        'organization_id' => $integration->organization_id,
    ]);

    $payload = new Netsuite($integrationCustomer, $invoice);

    // Without the currencies mapping the column is absent.
    expect($payload->body()['columns']['currency'] ?? null)->toBeNull();

    NetsuiteCollectionMapping::factory()->currencies()->forIntegration($integration)->create([
        'organization_id' => $organization->id,
    ]);

    expect((new Netsuite($integrationCustomer, $invoice))->body()['columns']['currency'])->toBe('3');
});

it('builds the factory payloads through the mapping rows without errors', function (): void {
    [$organization, $customer, $invoice, $integration, $integrationCustomer] = payloadFixture(XeroIntegration::class);
    $fee = chargeFeeFixture($organization, $customer, $invoice);

    XeroMapping::factory()->forIntegration($integration)->forMappable('BillableMetric', $fee->charge->billableMetric)->create([
        'organization_id' => $integration->organization_id,
    ]);

    $payload = Factory::new_instance($integrationCustomer, $invoice);

    expect($payload)->toBeInstanceOf(Xero::class)
        ->and($payload->body()[0]['fees'][0]['item_code'])->toBe('xero-123');
});
