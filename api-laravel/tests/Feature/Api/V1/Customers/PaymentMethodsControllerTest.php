<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:GET:/api/v1/customers/:external_id/payment_methods',
    'ledger:rest:DELETE:/api/v1/customers/:external_id/payment_methods/:id',
    'ledger:rest:PUT:/api/v1/customers/:external_id/payment_methods/:id/set_as_default',
);

use App\Models\Customer;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\PaymentProvider;
use App\Models\PaymentProviderCustomer;

/**
 * Port of Rails' spec/requests/api/v1/customers/payment_methods_controller_spec.rb
 * — index, destroy (discard) and set_as_default over the customer's
 * provider payment methods.
 */
function paymentMethodsOrganization(): array
{
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $provider = PaymentProvider::factory()->forOrganization($organization)->create();
    $providerCustomer = PaymentProviderCustomer::factory()->forCustomer($customer)->create([
        'payment_provider_id' => $provider->id,
    ]);

    return [$organization, $organization->apiKeys()->first(), $customer, $provider];
}

function paymentMethodsMake(Customer $customer, array $attributes = []): PaymentMethod
{
    return PaymentMethod::factory()->forCustomer($customer)->create($attributes);
}

// -- GET index ---------------------------------------------------------------------

it('lists the customer payment methods', function (): void {
    [$organization, $apiKey, $customer] = paymentMethodsOrganization();
    $method = paymentMethodsMake($customer);

    $this->getJson('/api/v1/customers/'.$customer->external_id.'/payment_methods', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($method) {
            $json->count('payment_methods', 1)
                ->where('payment_methods.0.lago_id', $method->id)
                ->where('payment_methods.0.provider_method_id', $method->provider_method_id)
                ->where('payment_methods.0.is_default', false)
                ->where('meta.total_count', 1)
                ->etc();
        });
});

it('answers not_found for an unknown customer payment methods index', function (): void {
    [$organization, $apiKey] = paymentMethodsOrganization();

    $this->getJson('/api/v1/customers/unknown/payment_methods', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- DELETE destroy ------------------------------------------------------------------

it('discards a payment method', function (): void {
    [$organization, $apiKey, $customer] = paymentMethodsOrganization();
    $method = paymentMethodsMake($customer);

    $this->deleteJson('/api/v1/customers/'.$customer->external_id.'/payment_methods/'.$method->id, [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('payment_method.lago_id', $method->id);

    expect($method->refresh()->trashed())->toBeTrue();

    // Discarded methods are hidden from the index (Rails: default_scope kept).
    $this->getJson('/api/v1/customers/'.$customer->external_id.'/payment_methods', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) {
            $json->count('payment_methods', 0)
                ->etc();
        });
});

it('answers not_found when discarding an unknown payment method', function (): void {
    [$organization, $apiKey, $customer] = paymentMethodsOrganization();

    $this->deleteJson('/api/v1/customers/'.$customer->external_id.'/payment_methods/00000000-0000-0000-0000-000000000000', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound()
        ->assertJsonPath('code', 'payment_method_not_found');
});

// -- PUT set_as_default ----------------------------------------------------------------

it('sets a payment method as the customer default', function (): void {
    [$organization, $apiKey, $customer] = paymentMethodsOrganization();
    $currentDefault = paymentMethodsMake($customer, ['is_default' => true]);
    $method = paymentMethodsMake($customer);

    $this->putJson('/api/v1/customers/'.$customer->external_id.'/payment_methods/'.$method->id.'/set_as_default', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('payment_method.lago_id', $method->id)
        ->assertJsonPath('payment_method.is_default', true);

    expect($currentDefault->refresh()->is_default)->toBeFalse()
        ->and($method->refresh()->is_default)->toBeTrue();
});

it('is idempotent when the method is already the default', function (): void {
    [$organization, $apiKey, $customer] = paymentMethodsOrganization();
    $method = paymentMethodsMake($customer, ['is_default' => true]);

    $this->putJson('/api/v1/customers/'.$customer->external_id.'/payment_methods/'.$method->id.'/set_as_default', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('payment_method.is_default', true);
});

it('answers not_found when the payment method is unknown', function (): void {
    [$organization, $apiKey, $customer] = paymentMethodsOrganization();

    $this->putJson('/api/v1/customers/'.$customer->external_id.'/payment_methods/00000000-0000-0000-0000-000000000000/set_as_default', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound()
        ->assertJsonPath('code', 'payment_method_not_found');
});
