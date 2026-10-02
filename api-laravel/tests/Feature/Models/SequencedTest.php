<?php

use App\Models\BillingEntity;
use App\Models\Customer;
use App\Models\Exceptions\SequenceException;
use App\Models\Invoice;
use App\Models\Organization;
use App\Support\CurrentContext;
use Illuminate\Support\Facades\DB;

/**
 * Port of spec for Rails' Sequenced concern (used by Invoice with the
 * per-customer + per-billing-entity scope and lock key).
 */
it('assigns sequential ids per customer and billing entity inside a transaction', function () {
    $organization = CurrentContext::$organization = Organization::create(['name' => 'Acme Corp']);
    $entity = $organization->billingEntities()->create(['name' => 'BE', 'code' => 'be']);
    $customer = $organization->customers()->create(['external_id' => 'ext-1', 'name' => 'C1', 'billing_entity_id' => $entity->id]);
    $customer2 = $organization->customers()->create(['external_id' => 'ext-2', 'name' => 'C2', 'billing_entity_id' => $entity->id]);

    $invoiceA = null;
    $invoiceB = null;

    DB::transaction(function () use ($customer, $entity, &$invoiceA) {
        $invoiceA = $customer->invoices()->create([
            'billing_entity_id' => $entity->id,
            'organization_id' => $customer->organization_id,
            'status' => 1,
        ]);
    });

    DB::transaction(function () use ($customer, $entity, &$invoiceB) {
        $invoiceB = $customer->invoices()->create([
            'billing_entity_id' => $entity->id,
            'organization_id' => $customer->organization_id,
            'status' => 1,
        ]);
    });

    expect($invoiceA->sequential_id)->toBe(1)
        ->and($invoiceB->sequential_id)->toBe(2);

    // A different customer's invoice numbers from 1 again (scope isolation).
    DB::transaction(function () use ($customer2, $entity, &$invoiceC) {
        $invoiceC = $customer2->invoices()->create([
            'billing_entity_id' => $entity->id,
            'organization_id' => $customer2->organization_id,
            'status' => 1,
        ]);
    });

    expect($invoiceC->sequential_id)->toBe(1);
});

it('refuses to assign a sequential id outside a transaction', function () {
    $organization = CurrentContext::$organization = Organization::create(['name' => 'Acme Corp']);
    $entity = $organization->billingEntities()->create(['name' => 'BE', 'code' => 'be']);
    $customer = $organization->customers()->create(['external_id' => 'ext-1', 'name' => 'C1', 'billing_entity_id' => $entity->id]);

    // Leave the suite's wrapping transaction so the save really is outside one.
    DB::connection()->commit();

    $customer->invoices()->create([
        'billing_entity_id' => $entity->id,
        'organization_id' => $customer->organization_id,
        'status' => 1,
    ]);
})->throws(SequenceException::class, 'must be called inside a transaction');
