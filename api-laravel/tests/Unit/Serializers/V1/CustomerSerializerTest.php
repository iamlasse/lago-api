<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Organization;
use App\Support\CurrentContext;
use App\Models\CustomerMetadata;
use Database\Factories\TaxFactory;
use App\Serializers\V1\CustomerSerializer;

beforeEach(function () {
    CurrentContext::reset();
});

function serializedCustomer(Customer $customer, array $options = []): array
{
    $serializer = new CustomerSerializer($customer, [
        'root_name' => 'customer',
        'includes' => $options['includes'] ?? [],
        ...$options,
    ]);

    return json_decode($serializer->toJson(), true);
}

it('serializes the customer with literal snake_case keys', function () {
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create([
        'shipping_city' => 'Paris',
        'shipping_address_line1' => 'test1',
        'shipping_zipcode' => '002',
    ]);

    $metadata = CustomerMetadata::factory()->for($customer)->create();

    $tax = TaxFactory::new()->create(['organization_id' => $organization->id]);
    $customer->appliedTaxes()->create(['tax_id' => $tax->id, 'organization_id' => $organization->id]);

    $result = serializedCustomer($customer, ['includes' => ['taxes']])['customer'];

    expect($result['lago_id'])->toBe($customer->id)
        ->and($result['billing_entity_code'])->toBe($customer->billingEntity->code)
        ->and($result['external_id'])->toBe($customer->external_id)
        ->and($result['account_type'])->toBe('customer')
        ->and($result['name'])->toBe($customer->name)
        ->and($result['sequential_id'])->toBe($customer->sequential_id)
        ->and($result['slug'])->toBe($customer->slug)
        ->and($result['created_at'])->toBe($customer->created_at->utc()->format('Y-m-d\TH:i:s\Z'))
        ->and($result['updated_at'])->toBe($customer->updated_at->utc()->format('Y-m-d\TH:i:s\Z'))
        ->and($result['country'])->toBe($customer->country)
        ->and($result['currency'])->toBe($customer->currency)
        ->and($result['finalize_zero_amount_invoice'])->toBe('inherit')
        ->and($result['skip_invoice_custom_sections'])->toBeFalse()
        ->and($result['applicable_timezone'])->toBe('UTC')
        ->and($result['shipping_address'])->toBe([
            'address_line1' => 'test1',
            'address_line2' => null,
            'city' => 'Paris',
            'zipcode' => '002',
            'state' => null,
            'country' => null,
        ])
        ->and($result['metadata'])->toBe([[
            'lago_id' => $metadata->id,
            'key' => $metadata->key,
            'value' => $metadata->value,
            'display_in_invoice' => true,
            'created_at' => $metadata->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ]])
        ->and($result['taxes'][0]['lago_id'])->toBe($tax->id)
        ->and($result['billing_configuration'])->toBe([
            'invoice_grace_period' => null,
            'payment_provider' => null,
            'payment_provider_code' => null,
            'document_locale' => null,
            'subscription_invoice_issuing_date_anchor' => null,
            'subscription_invoice_issuing_date_adjustment' => null,
        ]);
})->group('ledger:ser:V1.CustomerSerializer');

it('emits taxes only when included', function () {
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create();

    $withoutTaxes = serializedCustomer($customer);

    expect(array_key_exists('taxes', $withoutTaxes['customer']))->toBeFalse()
        ->and(array_key_exists('metadata', $withoutTaxes['customer']))->toBeTrue();

    $withTaxes = serializedCustomer($customer, ['includes' => ['taxes']]);

    expect($withTaxes['customer']['taxes'])->toBe([]);
});

it('serializes the vies_check include from the options', function () {
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create();

    $result = serializedCustomer($customer, [
        'includes' => ['vies_check'],
        'vies_check' => ['custom_hash' => 'yes'],
    ]);

    expect($result['customer']['vies_check'])->toBe(['custom_hash' => 'yes']);
});

it('serializes partner accounts and customer types', function () {
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create([
        'account_type' => 'partner',
        'customer_type' => 'company',
    ]);

    $result = serializedCustomer($customer)['customer'];

    expect($result['account_type'])->toBe('partner')
        ->and($result['customer_type'])->toBe('company');
});
