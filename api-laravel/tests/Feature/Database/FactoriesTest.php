<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\CustomerTax;
use App\Models\Organization;
use App\Support\CurrentContext;
use App\Models\CustomerMetadata;
use Database\Factories\TaxFactory;
use Illuminate\Support\Facades\DB;
use Database\Factories\CustomerTaxFactory;

beforeEach(function (): void {
    CurrentContext::reset();
});

it('creates an organization with a default billing entity, api key and webhook endpoint', function (): void {
    $organization = Organization::factory()->create();

    expect($organization->billingEntities()->count())->toBe(1)
        ->and($organization->apiKeys()->count())->toBe(1)
        ->and($organization->webhookEndpoints()->count())->toBe(1)
        ->and($organization->slug)->not->toBeNull()
        ->and($organization->document_number_prefix)->not->toBeNull()
        ->and($organization->hmac_key)->not->toBeNull();

    $billingEntity = $organization->defaultBillingEntity;

    expect($billingEntity)->not->toBeNull()
        ->and($billingEntity->code)->toStartWith('entity_')
        ->and($billingEntity->document_number_prefix)->not->toBeNull()
        ->and($organization->default_currency)->toBe('USD');
});

it('creates a customer attached to the organization default billing entity', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create();

    expect($customer->billing_entity_id)->toBe($organization->fresh()->defaultBillingEntity->id)
        ->and($customer->organization_id)->toBe($organization->id)
        ->and($customer->sequential_id)->toBe(1)
        ->and($customer->slug)->toBe($organization->document_number_prefix.'-001');
});

it('creates supporting records', function (): void {
    $user = User::factory()->create();
    $membership = Membership::factory()->for($user)->create();
    $metadata = CustomerMetadata::factory()->create();
    $tax = TaxFactory::new()->create();
    $customerTax = CustomerTaxFactory::new()->create();

    expect($membership->user_id)->toBe($user->id)
        ->and($membership->status->value)->toBe(0)
        ->and($metadata->key)->toBe('lead_name')
        ->and($metadata->display_in_invoice)->toBeTrue()
        ->and($tax->rate)->toBe(20.0)
        ->and($customerTax)->toBeInstanceOf(CustomerTax::class)
        ->and(ApiKey::first()->permissions)->not->toBeNull();
});

it('creates customers inside a nested transaction', function (): void {
    CurrentContext::$organization = Organization::factory()->create();

    DB::transaction(function (): void {
        Customer::factory()->count(2)->create();
    });

    expect(Customer::count())->toBe(2);
});
