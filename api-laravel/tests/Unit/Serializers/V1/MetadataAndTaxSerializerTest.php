<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Organization;
use App\Support\CurrentContext;
use App\Models\CustomerMetadata;
use Database\Factories\TaxFactory;
use App\Serializers\V1\TaxSerializer;
use App\Serializers\V1\Customers\MetadataSerializer;

beforeEach(function () {
    CurrentContext::reset();
});

it('serializes metadata', function () {
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create();
    $metadata = CustomerMetadata::factory()->for($customer)->create();

    $serializer = new MetadataSerializer($metadata);
    $result = $serializer->serialize();

    expect($result)->toBe([
        'lago_id' => $metadata->id,
        'key' => 'lead_name',
        'value' => 'John Doe',
        'display_in_invoice' => true,
        'created_at' => $metadata->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
    ]);
})->group('ledger:ser:V1.Customers.MetadataSerializer');

it('serializes taxes with stubbed counts', function () {
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $tax = TaxFactory::new()->create(['organization_id' => $organization->id]);

    $serializer = new TaxSerializer($tax);
    $result = $serializer->serialize();

    expect($result)->toBe([
        'lago_id' => $tax->id,
        'name' => 'VAT',
        'code' => $tax->code,
        'rate' => 20.0,
        'description' => 'French Standard VAT',
        'applied_to_organization' => false,
        'add_ons_count' => 0,
        'customers_count' => 0,
        'plans_count' => 0,
        'charges_count' => 0,
        'commitments_count' => 0,
        'created_at' => $tax->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
    ]);
})->group('ledger:ser:V1.TaxSerializer');
