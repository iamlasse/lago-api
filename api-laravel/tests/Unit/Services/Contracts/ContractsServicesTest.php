<?php

declare(strict_types=1);

uses()->group('ledger:svc:Contracts.CreateService', 'ledger:svc:Contracts.TerminateService', 'ledger:svc:Contracts.UpdateService');

use App\Models\Contract;
use App\Models\Customer;
use App\Models\CatalogPlan;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use App\Services\Contracts\CreateService;
use App\Services\Contracts\UpdateService;
use App\Services\Contracts\TerminateService;

/**
 * Port of Rails' spec/services/contracts/*_service_spec.rb (the REST-facing
 * service behaviour; the HTTP layer is covered by the request specs).
 */
function contractsServiceOrg(): Organization
{
    return Organization::factory()->create(['feature_flags' => ['product_catalog']]);
}

it('creates a pending contract when the start is in the future', function (): void {
    $organization = contractsServiceOrg();
    $customer = Customer::factory()->create(['organization_id' => $organization->id, 'currency' => 'EUR']);

    $result = CreateService::call(organization: $organization, params: [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'future',
        'started_at' => \Illuminate\Support\Facades\Date::tomorrow()->toIso8601String(),
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->contract->getRawOriginal('status'))->toBe('pending')
        ->and($result->contract->getRawOriginal('billing_time'))->toBe('calendar');
});

it('rejects a malformed started_at', function (): void {
    $organization = contractsServiceOrg();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $result = CreateService::call(organization: $organization, params: [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'bad-date',
        'started_at' => 'not a date',
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['started_at' => ['value_is_invalid']]);
});

it('rejects an already-ended window on create', function (): void {
    $organization = contractsServiceOrg();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $result = CreateService::call(organization: $organization, params: [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'zombie',
        'ended_at' => \Illuminate\Support\Facades\Date::yesterday()->toIso8601String(),
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['ended_at' => ['already_ended']]);
});

it('terminates the active contract and cancels the pending one', function (): void {
    $organization = contractsServiceOrg();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $active = Contract::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'status' => 'active',
    ]);
    $pending = Contract::factory()->pending()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);

    $terminated = TerminateService::call(contract: $active);
    $canceled = TerminateService::call(contract: $pending);

    expect($terminated->success())->toBeTrue()
        ->and($terminated->contract->getRawOriginal('status'))->toBe('terminated')
        ->and($terminated->contract->terminated_at)->not->toBeNull()
        ->and($canceled->success())->toBeTrue()
        ->and($canceled->contract->getRawOriginal('status'))->toBe('canceled')
        ->and($canceled->contract->canceled_at)->not->toBeNull();
});

it('refuses to terminate an ended contract twice', function (): void {
    $organization = contractsServiceOrg();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $terminated = Contract::factory()->terminated()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);

    $result = TerminateService::call(contract: $terminated);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['contract' => ['cannot_terminate']]);
});

it('re-materializes rate cards when a pending contract changes plan', function (): void {
    $organization = contractsServiceOrg();
    $customer = Customer::factory()->create(['organization_id' => $organization->id, 'currency' => 'EUR']);

    $planA = CatalogPlan::factory()->create(['organization_id' => $organization->id, 'currency' => 'EUR']);
    $planB = CatalogPlan::factory()->create(['organization_id' => $organization->id, 'currency' => 'EUR']);

    $metric = App\Models\BillableMetric::factory()->create(['organization_id' => $organization->id]);
    $product = App\Models\Product::factory()->create([
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
    ]);
    $rateCard = App\Models\RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'currency' => 'EUR',
    ]);
    App\Models\PlanRateCard::factory()->create([
        'organization_id' => $organization->id,
        'catalog_plan_id' => $planB->id,
        'rate_card_id' => $rateCard->id,
    ]);

    $created = CreateService::call(organization: $organization, params: [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'reswap',
        'plan_code' => $planA->code,
        'started_at' => \Illuminate\Support\Facades\Date::tomorrow()->toIso8601String(),
    ]);

    expect($created->success())->toBeTrue()
        ->and($created->contract->appliedRateCards()->count())->toBe(0);

    $updated = UpdateService::call(
        contract: $created->contract,
        params: ['plan_code' => $planB->code],
    );

    expect($updated->success())->toBeTrue()
        ->and($updated->contract->catalog_plan_id)->toBe($planB->id)
        ->and($updated->contract->appliedRateCards()->count())->toBe(1)
        ->and($updated->contract->appliedRateCards()->first()->rate_card_id)->toBe($rateCard->id);
});

it('locks signed contracts against plan changes', function (): void {
    $organization = contractsServiceOrg();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $plan = CatalogPlan::factory()->create(['organization_id' => $organization->id]);
    $active = Contract::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'status' => 'active',
    ]);

    $result = UpdateService::call(contract: $active, params: ['plan_code' => $plan->code]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['contract' => ['contract_locked']]);
});
