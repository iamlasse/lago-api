<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Models\Organization;
use App\Models\BillableMetric;
use App\Support\CurrentContext;
use App\Services\Coupons\CreateService;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ValidationFailure;
use App\Services\Failures\MethodNotAllowedFailure;

beforeEach(function (): void {
    CurrentContext::reset();
    CurrentContext::$source = 'api';
});

function createCouponArgs(Organization $organization, array $overrides = []): array
{
    return [
        'name' => 'Summer promo',
        'code' => 'SUMMER',
        'organization_id' => $organization->id,
        'coupon_type' => 'fixed_amount',
        'amount_cents' => 2000,
        'amount_currency' => 'EUR',
        'frequency' => 'once',
        'expiration' => 'no_expiration',
        ...$overrides,
    ];
}

it('creates a coupon', function (): void {
    $organization = Organization::factory()->create();

    $result = CreateService::call(createCouponArgs($organization, ['description' => 'Coupon description']));

    expect($result->success())->toBeTrue()
        ->and(Coupon::count())->toBe(1)
        ->and($result->coupon->name)->toBe('Summer promo')
        ->and($result->coupon->code)->toBe('SUMMER')
        ->and($result->coupon->description)->toBe('Coupon description')
        ->and($result->coupon->typeEnum()?->label())->toBe('fixed_amount')
        ->and($result->coupon->frequencyEnum()?->label())->toBe('once')
        ->and($result->coupon->expirationEnum()?->label())->toBe('no_expiration')
        ->and($result->coupon->reusable)->toBeTrue()
        ->and($result->coupon->limited_plans)->toBeFalse()
        ->and($result->coupon->limited_billable_metrics)->toBeFalse();
})->group('ledger:svc:Coupons.CreateService');

it('creates a coupon as non reusable when explicitly requested', function (): void {
    $organization = Organization::factory()->create();

    $result = CreateService::call(createCouponArgs($organization, ['reusable' => false]));

    expect($result->success())->toBeTrue()
        ->and($result->coupon->reusable)->toBeFalse();
});

it('creates a percentage coupon', function (): void {
    $organization = Organization::factory()->create();

    $result = CreateService::call(createCouponArgs($organization, [
        'coupon_type' => 'percentage',
        'percentage_rate' => '20.5',
        'amount_cents' => null,
        'amount_currency' => null,
    ]));

    expect($result->success())->toBeTrue()
        ->and($result->coupon->percentage())->toBeTrue()
        ->and($result->coupon->percentage_rate)->toBe('20.50000');
});

it('creates a coupon limited to plans through coupon targets', function (): void {
    $organization = Organization::factory()->create();
    $plan = Plan::factory()->for($organization)->create();

    $result = CreateService::call(createCouponArgs($organization, [
        'applies_to' => ['plan_codes' => [$plan->code]],
    ]));

    expect($result->success())->toBeTrue()
        ->and($result->coupon->limited_plans)->toBeTrue()
        ->and($result->coupon->limited_billable_metrics)->toBeFalse()
        ->and(CouponTarget::count())->toBe(1)
        ->and($result->coupon->plans->first()->id)->toBe($plan->id);
});

it('creates a coupon limited to billable metrics through coupon targets', function (): void {
    $organization = Organization::factory()->create();
    $billableMetric = BillableMetric::factory()->for($organization)->create();

    $result = CreateService::call(createCouponArgs($organization, [
        'applies_to' => ['billable_metric_codes' => [$billableMetric->code]],
    ]));

    expect($result->success())->toBeTrue()
        ->and($result->coupon->limited_billable_metrics)->toBeTrue()
        ->and($result->coupon->limited_plans)->toBeFalse()
        ->and(CouponTarget::count())->toBe(1)
        ->and($result->coupon->billableMetrics->first()->id)->toBe($billableMetric->id);
});

it('does not allow both limitation types on one coupon', function (): void {
    $organization = Organization::factory()->create();
    $plan = Plan::factory()->for($organization)->create();
    $billableMetric = BillableMetric::factory()->for($organization)->create();

    $result = CreateService::call(createCouponArgs($organization, [
        'applies_to' => [
            'plan_codes' => [$plan->code],
            'billable_metric_codes' => [$billableMetric->code],
        ],
    ]));

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class)
        ->and($result->getError()->code)->toBe('only_one_limitation_type_per_coupon_allowed')
        ->and(Coupon::count())->toBe(0)
        ->and(CouponTarget::count())->toBe(0);
});

it('fails when a limited plan code does not exist', function (): void {
    $organization = Organization::factory()->create();

    $result = CreateService::call(createCouponArgs($organization, [
        'applies_to' => ['plan_codes' => ['unknown_code']],
    ]));

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->getMessage())->toBe('plans_not_found');
});

it('fails when a limited billable metric code does not exist', function (): void {
    $organization = Organization::factory()->create();

    $result = CreateService::call(createCouponArgs($organization, [
        'applies_to' => ['billable_metric_codes' => ['unknown_code']],
    ]));

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->getMessage())->toBe('billable_metrics_not_found');
});

it('fails when the expiration date is in the past', function (): void {
    $organization = Organization::factory()->create();

    $result = CreateService::call(createCouponArgs($organization, [
        'expiration' => 'time_limit',
        'expiration_at' => '2022-01-01T00:00:00Z',
    ]));

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['expiration_at'])->toBe(['invalid_date']);
});

it('fails when the expiration date format is invalid', function (): void {
    $organization = Organization::factory()->create();

    $result = CreateService::call(createCouponArgs($organization, [
        'expiration' => 'time_limit',
        'expiration_at' => 'not-a-date',
    ]));

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['expiration_at'])->toBe(['invalid_date']);
});

it('creates a coupon with a valid future expiration date', function (): void {
    $organization = Organization::factory()->create();

    $result = CreateService::call(createCouponArgs($organization, [
        'expiration' => 'time_limit',
        'expiration_at' => now()->addYear()->toISOString(),
    ]));

    expect($result->success())->toBeTrue()
        ->and($result->coupon->expiration_at)->not->toBeNull();
});

it('fails when the code already exists in the organization', function (): void {
    $organization = Organization::factory()->create();

    Coupon::factory()->for($organization)->create(['code' => 'SUMMER']);

    $result = CreateService::call(createCouponArgs($organization));

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['code'])->toBe(['value_already_exist']);
});

it('allows the same code in another organization', function (): void {
    Coupon::factory()->create(['code' => 'SUMMER']);
    $organization = Organization::factory()->create();

    $result = CreateService::call(createCouponArgs($organization));

    expect($result->success())->toBeTrue()
        ->and($result->coupon->code)->toBe('SUMMER');
});

it('fails when the name is missing', function (): void {
    $organization = Organization::factory()->create();

    $result = CreateService::call(createCouponArgs($organization, ['name' => '']));

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['name'])->toBe(['value_is_mandatory']);
});

it('fails when a percentage coupon has no rate', function (): void {
    $organization = Organization::factory()->create();

    $result = CreateService::call(createCouponArgs($organization, [
        'coupon_type' => 'percentage',
        'amount_cents' => null,
        'amount_currency' => null,
    ]));

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['percentage_rate'])->toBe(['value_is_mandatory']);
});

it('fails when a fixed amount coupon has no amount', function (): void {
    $organization = Organization::factory()->create();

    $result = CreateService::call(createCouponArgs($organization, ['amount_cents' => null]));

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['amount_cents'])->toBe(['value_is_mandatory']);
});

it('fails when the amount is not positive', function (): void {
    $organization = Organization::factory()->create();

    $result = CreateService::call(createCouponArgs($organization, ['amount_cents' => 0]));

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['amount_cents'])->toBe(['value_is_out_of_range']);
});

it('fails when a recurring coupon has no frequency duration', function (): void {
    $organization = Organization::factory()->create();

    $result = CreateService::call(createCouponArgs($organization, [
        'frequency' => 'recurring',
        'frequency_duration' => null,
    ]));

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['frequency_duration'])->toBe(['value_is_mandatory']);
});

it('fails when an unknown coupon type is provided', function (): void {
    $organization = Organization::factory()->create();

    $result = CreateService::call(createCouponArgs($organization, ['coupon_type' => 'not_a_type']));

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['coupon_type'])->toBe(['value_is_invalid']);
});
