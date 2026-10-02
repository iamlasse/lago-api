<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Organization;
use App\Models\BillingEntity;
use App\Support\CurrentContext;
use Database\Factories\TaxFactory;
use Illuminate\Support\Facades\DB;
use App\Services\Customers\CreateService;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ValidationFailure;

beforeEach(function (): void {
    CurrentContext::reset();
    CurrentContext::$source = 'graphql';
});

function createArgs(Organization $organization): array
{
    return [
        'external_id' => Illuminate\Support\Str::uuid(),
        'name' => 'Foo Bar',
        'currency' => 'EUR',
    ];
}

it('creates a new customer on the default billing entity', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(organization: $organization, args: createArgs($organization));

    expect($result->success())->toBeTrue();

    $customer = $result->customer;

    expect($customer->id)->not->toBeEmpty()
        ->and($customer->organization_id)->toBe($organization->id)
        ->and($customer->billing_entity_id)->toBe($organization->defaultBillingEntity->id)
        ->and($customer->name)->toBe('Foo Bar')
        ->and($customer->currency)->toBe('EUR')
        ->and($customer->sequential_id)->toBe(1)
        ->and($customer->slug)->not->toBeNull();
})->group('ledger:svc:Customers.CreateService');

it('creates a customer with shipping address and billing configuration', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $args = createArgs($organization);
    $args['billing_configuration'] = [
        'subscription_invoice_issuing_date_anchor' => 'current_period_end',
        'subscription_invoice_issuing_date_adjustment' => 'keep_anchor',
        'document_locale' => 'fr',
    ];
    $args['shipping_address'] = [
        'address_line1' => 'line1',
        'address_line2' => 'line2',
        'city' => 'Paris',
        'zipcode' => '123456',
        'state' => 'foobar',
        'country' => 'FR',
    ];

    $result = CreateService::call(organization: $organization, args: $args);

    $customer = $result->customer;

    expect($result->success())->toBeTrue()
        ->and($customer->shipping_address_line1)->toBe('line1')
        ->and($customer->shipping_address_line2)->toBe('line2')
        ->and($customer->shipping_city)->toBe('Paris')
        ->and($customer->shipping_zipcode)->toBe('123456')
        ->and($customer->shipping_state)->toBe('foobar')
        ->and($customer->shipping_country)->toBe('FR')
        ->and($customer->getRawOriginal('subscription_invoice_issuing_date_anchor'))->toBe('current_period_end')
        ->and($customer->getRawOriginal('subscription_invoice_issuing_date_adjustment'))->toBe('keep_anchor')
        ->and($customer->document_locale)->toBe('fr');
});

it('creates a customer with a customer type', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $args = createArgs($organization);
    $args['customer_type'] = 'individual';

    $result = CreateService::call(organization: $organization, args: $args);

    expect($result->success())->toBeTrue()
        ->and($result->customer->getRawOriginal('customer_type'))->toBe('individual');
});

it('creates a customer with metadata', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $args = createArgs($organization);
    $args['metadata'] = [
        ['key' => 'manager name', 'value' => 'John', 'display_in_invoice' => true],
        ['key' => 'manager address', 'value' => 'Test', 'display_in_invoice' => false],
    ];

    $result = CreateService::call(organization: $organization, args: $args);

    expect($result->success())->toBeTrue()
        ->and($result->customer->metadata()->count())->toBe(2);
});

it('rejects more than five metadata entries', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $args = createArgs($organization);
    $args['metadata'] = collect(range(1, 6))
        ->map(fn ($i) => ['key' => "key$i", 'value' => 'v'])
        ->all();

    $result = CreateService::call(organization: $organization, args: $args);

    expect($result->success())->toBeFalse()
        ->and($result->getError()->messages)->toBe(['metadata' => ['invalid_count']]);
});

it('fails when the customer external_id already exists', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $args = createArgs($organization);
    Customer::factory()->for($organization)->create(['external_id' => $args['external_id']]);

    $result = CreateService::call(organization: $organization, args: $args);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['external_id'])->toBe(['value_already_exist']);
});

it('fails without external_id', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $args = createArgs($organization);
    unset($args['external_id']);

    $result = CreateService::call(organization: $organization, args: $args);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class);
});

it('fails when the organization does not exist', function (): void {
    $result = CreateService::call(organization: null, args: ['external_id' => 'x']);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->resource)->toBe('organization');
});

it('fails when the organization has no active billing entity', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    DB::table('billing_entities')->where('organization_id', $organization->id)->update(['archived_at' => now()]);

    $result = CreateService::call(organization: $organization, args: createArgs($organization));

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->resource)->toBe('billing_entity');
});

it('fails when the billing entity code belongs to an archived billing entity', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $archived = BillingEntity::factory()->for($organization)->create(['archived_at' => now()]);

    $args = createArgs($organization);
    $args['billing_entity_code'] = $archived->code;

    $result = CreateService::call(organization: $organization, args: $args);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class);
});

it('resolves a specific billing entity by code', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $entity2 = BillingEntity::factory()->for($organization)->create();

    $args = createArgs($organization);
    $args['billing_entity_code'] = $entity2->code;

    $result = CreateService::call(organization: $organization, args: $args);

    expect($result->success())->toBeTrue()
        ->and($result->customer->billing_entity_id)->toBe($entity2->id);
});

it('applies eu auto taxes when the billing entity manages eu taxes', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $organization->defaultBillingEntity->update(['eu_tax_management' => true, 'country' => 'FR']);

    // Rails' Taxes::AutoGenerateService creates these when EU management is
    // enabled on the billing entity.
    TaxFactory::new()->create(['organization_id' => $organization->id,
        'code' => 'lago_eu_fr_standard',
        'rate' => 20.0,
        'auto_generated' => true,
    ]);

    $args = createArgs($organization);

    $result = CreateService::call(organization: $organization, args: $args);

    expect($result->success())->toBeTrue()
        ->and($result->customer->taxes()->pluck('code')->all())->toBe(['lago_eu_fr_standard']);
});

it('applies the requested tax codes to the customer', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $tax = TaxFactory::new()->create(['organization_id' => $organization->id, 'code' => 'custom-tax']);

    $args = createArgs($organization);
    $args['tax_codes'] = ['custom-tax'];

    $result = CreateService::call(organization: $organization, args: $args);

    expect($result->success())->toBeTrue()
        ->and($result->customer->taxes()->pluck('code')->all())->toBe([$tax->code]);
});

it('fails on an unknown tax code', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $args = createArgs($organization);
    $args['tax_codes'] = ['unknown'];

    $result = CreateService::call(organization: $organization, args: $args);

    expect($result->success())->toBeFalse()
        ->and($result->getError()->getMessage())->toBe('tax_not_found');
});

it('skips eu auto taxes (vies pending) when a tax identification number is set', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $organization->defaultBillingEntity->update(['eu_tax_management' => true, 'country' => 'FR']);

    $args = createArgs($organization);
    $args['tax_identification_number'] = 'FR12345678901';

    $result = CreateService::call(organization: $organization, args: $args);

    // Rails: the EuAutoTaxesService failure is not raised — the customer is
    // created and the VIES check is resolved asynchronously.
    expect($result->success())->toBeTrue()
        ->and($result->customer->taxes()->count())->toBe(0);
});
