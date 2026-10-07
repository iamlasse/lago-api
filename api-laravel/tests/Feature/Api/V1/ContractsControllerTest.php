<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:POST:/api/v2/contracts',
    'ledger:rest:GET:/api/v2/contracts',
    'ledger:rest:GET:/api/v2/contracts/:external_id',
    'ledger:rest:PUT:/api/v2/contracts/:external_id',
    'ledger:rest:PATCH:/api/v2/contracts/:external_id',
    'ledger:rest:DELETE:/api/v2/contracts/:external_id',
    'ledger:rest:GET:/api/v2/contracts/:external_id/applied_rate_cards',
    'ledger:rest:POST:/api/v2/contracts/:external_id/applied_rate_cards',
);

use App\Models\Product;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\RateCard;
use App\Models\CatalogPlan;
use Illuminate\Support\Str;
use App\Models\Organization;
use App\Models\BillableMetric;
use Illuminate\Support\Carbon;

/**
 * Port of Rails' spec/requests/api/v2/contracts_controller_spec.rb.
 */
function contractsTestOrganization(): array
{
    $organization = Organization::factory()->create(['feature_flags' => ['product_catalog']]);

    return [$organization, $organization->apiKeys()->first()];
}

function contractsTestCustomer(Organization $organization): Customer
{
    return Customer::factory()->create([
        'organization_id' => $organization->id,
        'external_id' => 'cust_'.Str::uuid(),
        'currency' => 'EUR',
    ]);
}

it('creates an active contract for a customer', function (): void {
    [$organization, $apiKey] = contractsTestOrganization();
    $customer = contractsTestCustomer($organization);

    $this->postJson('/api/v2/contracts', ['contract' => [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'contract_1',
        'name' => 'Master agreement',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($customer): void {
            $json->where('contract.external_id', 'contract_1')
                ->where('contract.external_customer_id', $customer->external_id)
                ->where('contract.status', 'active')
                ->where('contract.billing_time', 'calendar')
                ->where('contract.plan_code', null)
                ->where('contract.applied_rate_cards_count', 0)
                ->has('contract.lago_id')
                ->etc();
        });

    expect(Contract::count())->toBe(1);
});

it('creates a pending contract starting in the future', function (): void {
    [$organization, $apiKey] = contractsTestOrganization();
    $customer = contractsTestCustomer($organization);

    $this->postJson('/api/v2/contracts', ['contract' => [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'contract_future',
        'started_at' => \Illuminate\Support\Facades\Date::tomorrow()->toIso8601String(),
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('contract.status', 'pending')
            ->etc());
});

it('rejects a second live contract on the same external_id', function (): void {
    [$organization, $apiKey] = contractsTestOrganization();
    $customer = contractsTestCustomer($organization);

    Contract::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'external_id' => 'contract_1',
        'status' => 'active',
    ]);

    $this->postJson('/api/v2/contracts', ['contract' => [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'contract_1',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('error_details.external_id.0', 'value_already_exists')
            ->etc());
});

it('returns not_found for an unknown plan_code', function (): void {
    [$organization, $apiKey] = contractsTestOrganization();
    $customer = contractsTestCustomer($organization);

    $this->postJson('/api/v2/contracts', ['contract' => [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'contract_2',
        'plan_code' => 'nope',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound()
        // Rails: CreateService's not_found_failure!(resource: "plan") renders
        // "#{resource}_not_found" = "plan_not_found" (api_responses#not_found_error).
        ->assertJson(['status' => 404, 'error' => 'Not Found', 'code' => 'plan_not_found']);
});

it('materializes the plan rate cards onto the contract', function (): void {
    [$organization, $apiKey] = contractsTestOrganization();
    $customer = contractsTestCustomer($organization);

    $metric = BillableMetric::factory()->create(['organization_id' => $organization->id]);
    $product = Product::factory()->create([
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
    ]);
    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'currency' => 'EUR',
    ]);
    $plan = CatalogPlan::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);
    App\Models\PlanRateCard::factory()->create([
        'organization_id' => $organization->id,
        'catalog_plan_id' => $plan->id,
        'rate_card_id' => $rateCard->id,
        'units' => 3,
    ]);

    $this->postJson('/api/v2/contracts', ['contract' => [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'contract_plan',
        'plan_code' => $plan->code,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($plan): void {
            $json->where('contract.plan_code', $plan->code)
                ->where('contract.applied_rate_cards_count', 1)
                ->has('contract.applied_rate_cards', 1)
                ->where('contract.applied_rate_cards.0.units', '3.0')
                ->etc();
        });
});

it('terminates an active contract and cancels a pending one', function (): void {
    [$organization, $apiKey] = contractsTestOrganization();
    $customer = contractsTestCustomer($organization);

    $active = Contract::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'external_id' => 'term_active',
        'status' => 'active',
    ]);
    $pending = Contract::factory()->pending()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'external_id' => 'term_pending',
    ]);

    $this->deleteJson('/api/v2/contracts/term_active', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('contract.status', 'terminated')
            ->etc());

    $this->deleteJson('/api/v2/contracts/term_pending', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('contract.status', 'canceled')
            ->etc());

    expect($active->fresh()->terminated_at)->not->toBeNull()
        ->and($pending->fresh()->canceled_at)->not->toBeNull();
});

it('refuses to terminate an already-terminated contract', function (): void {
    [$organization, $apiKey] = contractsTestOrganization();
    $customer = contractsTestCustomer($organization);

    Contract::factory()->terminated()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'external_id' => 'done',
    ]);

    $this->deleteJson('/api/v2/contracts/done', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('error_details.contract.0', 'cannot_terminate')
            ->etc());
});

it('prefers the active contract for termination and the pending one for reads', function (): void {
    [$organization, $apiKey] = contractsTestOrganization();
    $customer = contractsTestCustomer($organization);

    Contract::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'external_id' => 'swap',
        'status' => 'active',
        'name' => 'active one',
    ]);
    Contract::factory()->pending()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'external_id' => 'swap',
        'name' => 'pending one',
    ]);

    // Terminate prefers the active sibling...
    $this->deleteJson('/api/v2/contracts/swap', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk();

    // ...show prefers the pending replacement (still live).
    $this->getJson('/api/v2/contracts/swap', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('contract.name', 'pending one')
            ->etc());
});

it('updates a pending contract plan and locks active ones', function (): void {
    [$organization, $apiKey] = contractsTestOrganization();
    $customer = contractsTestCustomer($organization);

    $plan = CatalogPlan::factory()->create(['organization_id' => $organization->id, 'currency' => 'EUR']);
    $pending = Contract::factory()->pending()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'external_id' => 'pending_plan',
    ]);

    $this->patchJson('/api/v2/contracts/pending_plan', ['contract' => [
        'plan_code' => $plan->code,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('contract.plan_code', $plan->code)
            ->etc());

    $active = Contract::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'external_id' => 'active_plan',
        'status' => 'active',
    ]);

    $this->patchJson('/api/v2/contracts/active_plan', ['contract' => [
        'plan_code' => $plan->code,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('error_details.contract.0', 'contract_locked')
            ->etc());

    expect($active->fresh()->catalog_plan_id)->toBeNull();
});

it('indexes contracts defaulting to active', function (): void {
    [$organization, $apiKey] = contractsTestOrganization();
    $customer = contractsTestCustomer($organization);

    Contract::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'external_id' => 'idx_active',
    ]);
    Contract::factory()->pending()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'external_id' => 'idx_pending',
    ]);

    $this->getJson('/api/v2/contracts', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->count('contracts', 1)
            ->where('contracts.0.external_id', 'idx_active')
            ->has('meta')
            ->etc());
});

it('indexes a contract by search_term', function (): void {
    [$organization, $apiKey] = contractsTestOrganization();
    $customer = contractsTestCustomer($organization);

    Contract::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'external_id' => 'findable',
        'name' => 'Acme master agreement',
    ]);

    $this->getJson('/api/v2/contracts?search_term=Acme', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->count('contracts', 1)
            ->etc());
});

// -- Nested applied rate cards -------------------------------------------------------

it('attaches a rate card to a pending contract', function (): void {
    [$organization, $apiKey] = contractsTestOrganization();
    $customer = contractsTestCustomer($organization);

    $metric = BillableMetric::factory()->create(['organization_id' => $organization->id]);
    $product = Product::factory()->create([
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
    ]);
    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'currency' => 'EUR',
    ]);
    $contract = Contract::factory()->pending()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'external_id' => 'carded',
    ]);

    $this->postJson('/api/v2/contracts/carded/applied_rate_cards', ['applied_rate_card' => [
        'rate_card_code' => $rateCard->code,
        'units' => 2,
        'rate_phases' => [
            ['code' => 'ramp_up', 'position' => 1, 'billing_interval_cycle_count' => 3],
            ['code' => 'steady', 'position' => 2, 'rate_override' => [
                'rate_model' => 'standard',
                'rate_properties' => ['amount' => '50'],
            ]],
        ],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->where('applied_rate_card.units', '2.0')
                ->where('applied_rate_card.rate_phases_count', 2)
                ->etc();
        });

    expect(App\Models\ContractRateCard::count())->toBe(1)
        ->and(App\Models\RatePhase::count())->toBe(2)
        ->and(App\Models\RateOverride::count())->toBe(1);
});

it('rejects a currency mismatch on attach', function (): void {
    [$organization, $apiKey] = contractsTestOrganization();
    $customer = contractsTestCustomer($organization);

    $metric = BillableMetric::factory()->create(['organization_id' => $organization->id]);
    $product = Product::factory()->create([
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
    ]);
    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'currency' => 'USD',
    ]);
    Contract::factory()->pending()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'external_id' => 'mismatch',
    ]);

    $this->postJson('/api/v2/contracts/mismatch/applied_rate_cards', ['applied_rate_card' => [
        'rate_card_code' => $rateCard->code,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('error_details.currency.0', 'currency_does_not_match')
            ->etc());
});

it('refuses authoring once the contract is active', function (): void {
    [$organization, $apiKey] = contractsTestOrganization();
    $customer = contractsTestCustomer($organization);

    $metric = BillableMetric::factory()->create(['organization_id' => $organization->id]);
    $product = Product::factory()->create([
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
    ]);
    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'currency' => 'EUR',
    ]);
    Contract::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'external_id' => 'signed',
        'status' => 'active',
    ]);

    $this->postJson('/api/v2/contracts/signed/applied_rate_cards', ['applied_rate_card' => [
        'rate_card_code' => $rateCard->code,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('error_details.contract.0', 'contract_locked')
            ->etc());
});

it('rejects a second card pricing the same product slice', function (): void {
    [$organization, $apiKey] = contractsTestOrganization();
    $customer = contractsTestCustomer($organization);

    $metric = BillableMetric::factory()->create(['organization_id' => $organization->id]);
    $product = Product::factory()->create([
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
    ]);
    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'currency' => 'EUR',
    ]);
    $contract = Contract::factory()->pending()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'external_id' => 'twice',
    ]);

    $payload = ['applied_rate_card' => ['rate_card_code' => $rateCard->code]];
    $headers = ['Authorization' => 'Bearer '.$apiKey->value];

    $this->postJson('/api/v2/contracts/twice/applied_rate_cards', $payload, $headers)->assertOk();
    $this->postJson('/api/v2/contracts/twice/applied_rate_cards', $payload, $headers)
        ->assertUnprocessable()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('error_details.rate_card.0', 'product_already_priced')
            ->etc());
});

it('lists a contract rate card phases', function (): void {
    [$organization, $apiKey] = contractsTestOrganization();
    $customer = contractsTestCustomer($organization);

    $metric = BillableMetric::factory()->create(['organization_id' => $organization->id]);
    $product = Product::factory()->create([
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
    ]);
    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'currency' => 'EUR',
    ]);
    $contract = Contract::factory()->pending()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'external_id' => 'phased',
    ]);

    $card = App\Models\ContractRateCard::factory()->create([
        'organization_id' => $organization->id,
        'contract_id' => $contract->id,
        'rate_card_id' => $rateCard->id,
    ]);
    App\Models\RatePhase::factory()->forContractRateCard($card)->create(['code' => 'default', 'position' => 1]);

    $this->getJson('/api/v2/contracts/phased/applied_rate_cards/'.$rateCard->code.'/rate_phases', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->count('rate_phases', 1)
            ->where('rate_phases.0.code', 'default')
            ->etc());
});

it('returns 403 without the product_catalog feature flag', function (): void {
    $organization = Organization::factory()->create(['feature_flags' => []]);
    $apiKey = $organization->apiKeys()->first();

    $this->getJson('/api/v2/contracts', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertForbidden();
});
