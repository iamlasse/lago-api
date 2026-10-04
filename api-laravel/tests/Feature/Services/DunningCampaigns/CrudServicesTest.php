<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\DunningCampaign;
use App\Models\DunningCampaignThreshold;
use App\Services\DunningCampaigns\CreateService;
use App\Services\DunningCampaigns\UpdateService;
use App\Services\DunningCampaigns\DestroyService;

/**
 * Ports of Rails' spec/services/dunning_campaigns/{create,update,
 * destroy}_service_spec.rb. Ledger rows: svc:dunning_campaigns:create /
 * update / destroy.
 */
beforeEach(function (): void {
    config(['lago.license' => 'premium-license-token']);
});

afterEach(function (): void {
    config(['lago.license' => null]);
});

function dunningOrganization(array $attributes = []): Organization
{
    // Rails: organization with the auto_dunning premium integration.
    return Organization::factory()->create(array_merge([
        'premium_integrations' => ['auto_dunning'],
    ], $attributes));
}

function dunningThresholdsInput(array $overrides = []): array
{
    return array_merge([[
        'currency' => 'EUR',
        'amount_cents' => 500,
    ]], $overrides);
}

// -- CreateService ----------------------------------------------------------

it('creates a dunning campaign with its thresholds', function (): void {
    $organization = dunningOrganization();

    $result = CreateService::call(organization: $organization, params: [
        'name' => 'Dunning',
        'code' => 'dunning_1',
        'description' => 'Campaign',
        'days_between_attempts' => 2,
        'max_attempts' => 3,
        'bcc_emails' => ['billing@acme.com'],
        'thresholds' => dunningThresholdsInput([
            ['currency' => 'USD', 'amount_cents' => 1000],
        ]),
    ]);

    expect($result->success())->toBeTrue();

    $campaign = $result->dunning_campaign;

    expect($campaign->name)->toBe('Dunning')
        ->and($campaign->code)->toBe('dunning_1')
        ->and($campaign->days_between_attempts)->toBe(2)
        ->and($campaign->max_attempts)->toBe(3)
        ->and($campaign->bcc_emails)->toBe(['billing@acme.com']);

    $thresholds = $campaign->thresholds()->orderBy('currency')->get();

    expect($thresholds)->toHaveCount(2)
        ->and($thresholds->firstWhere('currency', 'USD')->amount_cents)->toBe(1000)
        ->and($thresholds->firstWhere('currency', 'EUR')->amount_cents)->toBe(500);
});

it('is forbidden without the auto_dunning entitlement', function (): void {
    $organization = Organization::factory()->create();

    $result = CreateService::call(organization: $organization, params: [
        'name' => 'Dunning', 'code' => 'dunning_1', 'thresholds' => dunningThresholdsInput(),
    ]);

    expect($result->success())->toBeFalse();
});

it('validates the thresholds are present', function (): void {
    $organization = dunningOrganization();

    $result = CreateService::call(organization: $organization, params: [
        'name' => 'Dunning', 'code' => 'dunning_1', 'thresholds' => [],
    ]);

    expect($result->success())->toBeFalse()
        ->and(DunningCampaign::query()->where('code', 'dunning_1')->exists())->toBeFalse();
});

it('rejects a duplicate code within the organization', function (): void {
    $organization = dunningOrganization();

    DunningCampaign::factory()->forOrganization($organization)->create(['code' => 'dunning_1']);

    $result = CreateService::call(organization: $organization, params: [
        'name' => 'Dunning', 'code' => 'dunning_1', 'thresholds' => dunningThresholdsInput(),
    ]);

    expect($result->success())->toBeFalse();
});

it('attaches the campaign to the default billing entity when applied to the organization', function (): void {
    $organization = dunningOrganization();

    $first = CreateService::call(organization: $organization, params: [
        'name' => 'First', 'code' => 'first', 'applied_to_organization' => true,
        'thresholds' => dunningThresholdsInput(),
    ])->dunning_campaign;

    $defaultEntity = $organization->defaultBillingEntity()->first();

    expect($defaultEntity->applied_dunning_campaign_id)->toBe($first->id)
        ->and($first->applied_to_organization)->toBeTrue();

    // A second applied campaign demotes the first one.
    $second = CreateService::call(organization: $organization, params: [
        'name' => 'Second', 'code' => 'second', 'applied_to_organization' => true,
        'thresholds' => dunningThresholdsInput(),
    ])->dunning_campaign;

    expect($defaultEntity->refresh()->applied_dunning_campaign_id)->toBe($second->id)
        ->and($first->refresh()->applied_to_organization)->toBeFalse()
        ->and($second->refresh()->applied_to_organization)->toBeTrue();
});

// -- UpdateService ----------------------------------------------------------

it('updates the campaign attributes and replaces the threshold set', function (): void {
    $organization = dunningOrganization();
    $campaign = DunningCampaign::factory()->forOrganization($organization)->create(['name' => 'Old']);
    $kept = DunningCampaignThreshold::factory()->forCampaign($campaign)->forCurrency('EUR', 500)->create();
    $dropped = DunningCampaignThreshold::factory()->forCampaign($campaign)->forCurrency('USD', 700)->create();

    $result = UpdateService::call(organization: $organization, dunningCampaign: $campaign, params: [
        'name' => 'New name',
        'thresholds' => [
            ['id' => $kept->id, 'currency' => 'EUR', 'amount_cents' => 900],
            ['currency' => 'GBP', 'amount_cents' => 100],
        ],
    ]);

    expect($result->success())->toBeTrue();

    $campaign = $result->dunning_campaign->refresh();

    expect($campaign->name)->toBe('New name')
        // dropped is discarded, kept is updated in place, GBP is created.
        ->and($campaign->thresholds()->count())->toBe(2)
        ->and($kept->refresh()->amount_cents)->toBe(900)
        ->and($dropped->refresh()->deleted_at)->not->toBeNull();

    $created = $campaign->thresholds()->where('currency', 'GBP')->first();

    expect($created?->amount_cents)->toBe(100);
});

it('answers not_found for an unknown campaign', function (): void {
    $organization = dunningOrganization();

    $result = UpdateService::call(organization: $organization, dunningCampaign: null, params: [
        'name' => 'X',
    ]);

    expect($result->success())->toBeFalse();
});

it('resets dunning bookkeeping of customers below the new thresholds', function (): void {
    $organization = dunningOrganization();
    $campaign = DunningCampaign::factory()->forOrganization($organization)->create();

    // The original threshold gets discarded by the update (Rails: a
    // discarded threshold marks thresholds_updated, which arms the reset).
    DunningCampaignThreshold::factory()->forCampaign($campaign)->forCurrency('USD', 500)->create();

    // A customer explicitly applied, overdue in EUR below the NEW threshold.
    $customer = Customer::factory()->forOrganization($organization)->create([
        'applied_dunning_campaign_id' => $campaign->id,
        'dunning_currency_attempts' => ['EUR' => 1],
        'last_dunning_campaign_attempt' => 1,
        'last_dunning_campaign_attempt_at' => now(),
    ]);
    Invoice::factory()->for($customer, 'customer')->for($organization, 'organization')
        ->create(['currency' => 'EUR', 'total_amount_cents' => 100, 'payment_overdue' => true, 'ready_for_payment_processing' => true]);

    $result = UpdateService::call(organization: $organization, dunningCampaign: $campaign, params: [
        // Only the EUR threshold remains — the USD one is discarded.
        'thresholds' => [['currency' => 'EUR', 'amount_cents' => 5000]],
    ]);

    expect($result->success())->toBeTrue();

    $customer = $customer->refresh();

    expect($customer->dunning_currency_attempts)->toBe([])
        ->and($customer->last_dunning_campaign_attempt)->toBe(0)
        ->and($customer->last_dunning_campaign_attempt_at)->toBeNull();
});

// -- DestroyService ---------------------------------------------------------

it('discards the campaign and resets its customers', function (): void {
    $organization = dunningOrganization();
    $campaign = DunningCampaign::factory()->forOrganization($organization)->create();
    DunningCampaignThreshold::factory()->forCampaign($campaign)->create();

    $customer = Customer::factory()->forOrganization($organization)->create([
        'applied_dunning_campaign_id' => $campaign->id,
        'dunning_currency_attempts' => ['EUR' => 2],
        'last_dunning_campaign_attempt' => 1,
    ]);

    $result = DestroyService::call(dunningCampaign: $campaign);

    expect($result->success())->toBeTrue()
        ->and($campaign->refresh()->deleted_at)->not->toBeNull()
        // kept-scoped queries no longer see the campaign.
        ->and(DunningCampaign::query()->whereKey($campaign->id)->exists())->toBeFalse()
        ->and(DunningCampaignThreshold::withTrashed()->where('dunning_campaign_id', $campaign->id)->get()->every(fn ($t) => $t->deleted_at !== null))->toBeTrue()
        ->and($customer->refresh()->applied_dunning_campaign_id)->toBeNull()
        ->and($customer->dunning_currency_attempts)->toBe([])
        ->and($customer->last_dunning_campaign_attempt)->toBe(0);
});

it('detaches the default billing entity on destroy', function (): void {
    $organization = dunningOrganization();
    $campaign = DunningCampaign::factory()->forOrganization($organization)->create();

    $organization->defaultBillingEntity()->first()->update([
        'applied_dunning_campaign_id' => $campaign->id,
    ]);

    DestroyService::call(dunningCampaign: $campaign);

    expect($organization->defaultBillingEntity()->first()->refresh()->applied_dunning_campaign_id)->toBeNull();
});
