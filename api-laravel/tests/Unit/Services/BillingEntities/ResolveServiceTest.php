<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\BillingEntity;
use App\Support\CurrentContext;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\NotFoundFailure;
use App\Services\BillingEntities\ResolveService;

beforeEach(function (): void {
    CurrentContext::reset();
});

it('fails when the organization has no active billing entity', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $code = $organization->allBillingEntities()->first()->code;

    DB::table('billing_entities')
        ->where('organization_id', $organization->id)
        ->update(['archived_at' => now()]);

    $result = ResolveService::call(organization: $organization, billingEntityCode: $code);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->resource)->toBe('billing_entity')
        ->and($result->getError()->getMessage())->toBe('billing_entity_not_found');
})->group('ledger:svc:BillingEntities.ResolveService');

it('returns the default billing entity when no code is given', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $extra = BillingEntity::factory()->count(3)->for($organization)->create();

    $result = ResolveService::call(organization: $organization);

    expect($result->success())->toBeTrue()
        ->and($result->billing_entity->id)->toBe($organization->defaultBillingEntity->id)
        ->and($result->billing_entity->id)->not->toBe($extra->first()->id);
});

it('fails when the code does not match any billing entity', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = ResolveService::call(organization: $organization, billingEntityCode: '123');

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class);
});

it('returns the billing entity matching the code', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $first = BillingEntity::factory()->for($organization)->create();
    $second = BillingEntity::factory()->for($organization)->create(['code' => '123']);

    $result = ResolveService::call(organization: $organization, billingEntityCode: '123');

    expect($result->success())->toBeTrue()
        ->and($result->billing_entity->id)->toBe($second->id)
        ->and($result->billing_entity->id)->not->toBe($first->id);
});
