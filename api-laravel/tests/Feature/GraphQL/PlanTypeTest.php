<?php

declare(strict_types=1);

uses()->group('gql:types:plan', 'gql:types:charge');

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\Charge;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Enums\InvoiceStatus;

/**
 * Ports of the frozen SDL `Plan` / `Charge` type resolvers the Lago front's
 * PlanItem fragment (front/src/pages/PlansList.tsx) and the plan details
 * usage-charge section render — Rails Types::Plans::Object +
 * Types::Charges::Object semantics.
 */
function gqlPlanTypeSetup(): array
{
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser('plan-type-'.uniqid().'@example.com');

    gqlCreateMembership($user, $organization);

    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->for($organization)->create();

    $standardCharge = Charge::factory()->standard()->forPlan($plan)->create([
        'organization_id' => $organization->id,
        'code' => 'gql_std_charge',
        'properties' => ['amount' => '100', 'free_units' => 10, 'package_size' => 10],
    ]);
    Charge::factory()->graduated()->forPlan($plan)->create([
        'organization_id' => $organization->id,
        'code' => 'gql_graduated_charge',
    ]);

    $subscription = Subscription::factory()->for($customer)->for($plan)->create([
        'organization_id' => $organization->id,
        'external_id' => 'gql-plan-type-sub-1',
        'status' => 1,
    ]);

    $draftInvoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'status' => InvoiceStatus::Draft,
    ]);
    $draftInvoice->invoiceSubscriptions()->create([
        'organization_id' => $organization->id,
        'subscription_id' => $subscription->id,
        'recurring' => true,
    ]);

    return [$organization->refresh(), $user, $plan, $standardCharge, $subscription];
}

const PLAN_ITEM_FIELDS = <<<'GQL'
id
name
code
interval
amountCents
amountCurrency
chargesCount
activeSubscriptionsCount
draftInvoicesCount
hasActiveSubscriptions
hasDraftInvoices
hasCharges
hasCustomers
hasSubscriptions
hasOverriddenPlans
isOverridden
subscriptionsCount
customersCount
createdAt
GQL;

it('renders the PlanItem fields over the plans query', function (): void {
    [$organization, $user, $plan] = gqlPlanTypeSetup();

    $planItemFields = PLAN_ITEM_FIELDS;

    $response = gqlPost(
        <<<GQL
query {
    plans(limit: 10) {
        collection { {$planItemFields} }
    }
}
GQL,
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json();

    if (($payload['errors'] ?? null) !== null) {
        dump('GQLERR: '.json_encode($payload['errors']));
    }

    $collection = collect($payload['data']['plans']['collection'] ?? []);
    $row = $collection->firstWhere('code', $plan->code);

    expect($payload["errors"] ?? null)->toBeNull()
        ->and($row)->not->toBeNull()
        ->and($row['id'])->toBe($plan->id)
        ->and($row['name'])->toBe($plan->name)
        ->and($row['interval'])->toBe('monthly')
        ->and($row['amountCents'])->toBe('100')
        ->and($row['amountCurrency'])->toBe('EUR')
        ->and($row['chargesCount'])->toBe(2)
        ->and($row['activeSubscriptionsCount'])->toBe(1)
        ->and($row['draftInvoicesCount'])->toBe(1)
        ->and($row['hasCharges'])->toBeTrue()
        ->and($row['hasActiveSubscriptions'])->toBeTrue()
        ->and($row['hasCustomers'])->toBeTrue()
        ->and($row['hasDraftInvoices'])->toBeTrue()
        ->and($row['hasSubscriptions'])->toBeTrue()
        ->and($row['hasOverriddenPlans'])->toBeFalse()
        ->and($row['isOverridden'])->toBeFalse();
})->coversClass(App\GraphQL\Types\Plan::class);

it('serializes charges with enum names and the properties hash', function (): void {
    [$organization, $user, $plan, $standardCharge] = gqlPlanTypeSetup();

    $response = gqlPost(
        <<<'GQL'
query {
    plans(limit: 10) {
        collection {
            code
            charges {
                code
                chargeModel
                invoiceable
                minAmountCents
                payInAdvance
                prorated
                regroupPaidFees
                properties {
                    amount
                    freeUnits
                    packageSize
                    graduatedRanges { fromValue toValue perUnitAmount flatAmount }
                }
            }
        }
    }
}
GQL,
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json();

    if (($payload['errors'] ?? null) !== null) {
        dump('GQLERR: '.json_encode($payload['errors']));
    }

    $charges = collect($payload['data']['plans']['collection'][0]['charges'] ?? []);
    $standard = $charges->firstWhere('code', 'gql_std_charge');
    $graduated = $charges->firstWhere('code', 'gql_graduated_charge');

    expect($payload["errors"] ?? null)->toBeNull()
        ->and($standard['chargeModel'])->toBe('standard')
        ->and($standard['invoiceable'])->toBeTrue()
        ->and($standard['minAmountCents'])->toBe('0')
        ->and($standard['payInAdvance'])->toBeFalse()
        ->and($standard['prorated'])->toBeFalse()
        ->and($standard['properties']['amount'])->toBe('100')
        ->and($standard['properties']['freeUnits'])->toBe('10')
        ->and($standard['properties']['packageSize'])->toBe('10')
        ->and($graduated['chargeModel'])->toBe('graduated')
        ->and($graduated['properties']['graduatedRanges'][0]['flatAmount'])->toBe('200')
        ->and($graduated['properties']['graduatedRanges'][1]['toValue'])->toBeNull();
})->coversClass(App\GraphQL\Types\Charge::class);

it('maps the integer regroup_paid_fees column to the enum name', function (): void {
    [$organization, $user, $plan] = gqlPlanTypeSetup();

    Charge::factory()->standard()->forPlan($plan)->create([
        'organization_id' => $organization->id,
        'code' => 'gql_regroup_charge',
        'pay_in_advance' => true,
        'invoiceable' => false,
        'regroup_paid_fees' => 0,
    ]);

    $response = gqlPost(
        <<<'GQL'
query {
    plans(limit: 10) {
        collection {
            charges {
                code
                regroupPaidFees
            }
        }
    }
}
GQL,
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json();

    if (($payload['errors'] ?? null) !== null) {
        dump('GQLERR: '.json_encode($payload['errors']));
    }

    $charges = collect($payload['data']['plans']['collection'][0]['charges'] ?? []);
    $regrouped = $charges->firstWhere('code', 'gql_regroup_charge');

    expect($payload["errors"] ?? null)->toBeNull()
        ->and($regrouped['regroupPaidFees'])->toBe('invoice');
})->coversClass(App\GraphQL\Types\Charge::class);

it('counts the override children in the plan counts', function (): void {
    [$organization, $user, $plan] = gqlPlanTypeSetup();

    $childPlan = Plan::factory()->create([
        'organization_id' => $organization->id,
        'parent_id' => $plan->id,
        'code' => $plan->code.'_child',
    ]);
    $childCustomer = Customer::factory()->for($organization)->create();
    Subscription::factory()->for($childCustomer)->for($childPlan)->create([
        'organization_id' => $organization->id,
        'external_id' => 'gql-plan-type-sub-child',
        'status' => 1,
    ]);

    $response = gqlPost(
        <<<'GQL'
query {
    plans(limit: 10) {
        collection {
            code
            activeSubscriptionsCount
            customersCount
            subscriptionsCount
            hasOverriddenPlans
            hasActiveSubscriptions
            isOverridden
        }
    }
}
GQL,
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json();

    if (($payload['errors'] ?? null) !== null) {
        dump('GQLERR: '.json_encode($payload['errors']));
    }

    $collection = collect($payload['data']['plans']['collection'] ?? []);
    $parent = $collection->firstWhere('code', $plan->code);

    expect($payload['errors'] ?? null)->toBeNull()
        ->and($parent['activeSubscriptionsCount'])->toBe(2)
        ->and($parent['customersCount'])->toBe(2)
        ->and($parent['subscriptionsCount'])->toBe(2)
        ->and($parent['hasOverriddenPlans'])->toBeTrue()
        ->and($collection->firstWhere('code', $childPlan->code))->toBeNull();
});

it('surfaces isOverridden on an override child plan', function (): void {
    [$organization, $user, $plan] = gqlPlanTypeSetup();

    $childPlan = Plan::factory()->create([
        'organization_id' => $organization->id,
        'parent_id' => $plan->id,
        'code' => $plan->code.'_child2',
    ]);

    $response = gqlPost(
        <<<'GQL'
query($id: ID!) {
    subscription(id: $id) {
        plan {
            code
            isOverridden
            hasOverriddenPlans
        }
    }
}
GQL,
        ['id' => $childPlan->subscriptions()->first()?->id ?? $childPlan->id],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json();

    if (($payload['errors'] ?? null) !== null) {
        dump('GQLERR: '.json_encode($payload['errors']));
    }

    $planData = $payload['data']['subscription']['plan'] ?? null;

    expect($payload['errors'] ?? null)->toBeNull()
        ->and($planData['code'])->toBe($childPlan->code)
        ->and($planData['isOverridden'])->toBeTrue()
        ->and($planData['hasOverriddenPlans'])->toBeFalse();
});
