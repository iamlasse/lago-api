<?php

declare(strict_types=1);

require_once __DIR__.'/../../../Unit/Services/Charges/Validators/ValidatorTestCase.php';

use App\Models\Plan;
use App\Models\Charge;
use App\Enums\PlanInterval;
use Illuminate\Support\Str;
use App\Models\BillableMetric;
use App\Services\Plans\CreateService;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ValidationFailure;

/**
 * Port of spec/services/plans/create_service_spec.rb (core scenarios).
 */
function planCreateArgs(): array
{
    return [
        'name' => 'Go Classic',
        'code' => Str::uuid(),
        'interval' => 'monthly',
        'pay_in_advance' => false,
        'amount_cents' => 100,
        'amount_currency' => 'EUR',
    ];
}

it('creates a plan', function (): void {
    $args = planCreateArgs();
    $args['organization_id'] = App\Models\Organization::factory()->create()->id;

    $result = CreateService::call(args: $args);

    expect($result->success())->toBeTrue()
        ->and($result->plan->name)->toBe('Go Classic')
        ->and($result->plan->code)->toBe($args['code'])
        ->and($result->plan->interval)->toBe(PlanInterval::Monthly->value)
        ->and($result->plan->amount_cents)->toBe(100)
        ->and($result->plan->amount_currency)->toBe('EUR')
        ->and($result->plan->pay_in_advance)->toBeFalse()
        ->and($result->plan->pending_deletion)->toBeFalse();
})->group('ledger:svc:Plans.CreateService');

it('creates nested charges', function (): void {
    $organization = App\Models\Organization::factory()->create();
    $metric = BillableMetric::factory()->create(['organization_id' => $organization->id]);

    $args = planCreateArgs();
    $args['organization_id'] = $organization->id;
    $args['charges'] = [[
        'billable_metric_id' => $metric->id,
        'charge_model' => 'graduated',
        'properties' => [
            'graduated_ranges' => [
                ['from_value' => 0, 'to_value' => 10, 'per_unit_amount' => '0', 'flat_amount' => '200'],
                ['from_value' => 11, 'to_value' => null, 'per_unit_amount' => '0', 'flat_amount' => '300'],
            ],
        ],
    ]];

    $result = CreateService::call(args: $args);

    expect($result->success())->toBeTrue();

    $charges = $result->plan->charges()->get();

    expect($charges)->toHaveCount(1)
        ->and($charges[0]->charge_model)->toBe(App\Enums\ChargeModel::Graduated->value)
        ->and($charges[0]->properties['graduated_ranges'])->toHaveCount(2);
});

it('auto-generates charge codes when not provided', function (): void {
    $organization = App\Models\Organization::factory()->create();
    $metric = BillableMetric::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'api-calls',
    ]);

    $args = planCreateArgs();
    $args['organization_id'] = $organization->id;
    $args['charges'] = [[
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'properties' => ['amount' => '10'],
    ]];

    $result = CreateService::call(args: $args);

    expect($result->success())->toBeTrue()
        ->and($result->plan->charges()->first()->code)->toBe('api-calls');
});

it('creates fixed charges with auto-generated codes', function (): void {
    $organization = App\Models\Organization::factory()->create();
    $addOn = App\Models\AddOn::factory()->create(['organization_id' => $organization->id]);

    $args = planCreateArgs();
    $args['organization_id'] = $organization->id;
    $args['fixed_charges'] = [[
        'add_on_id' => $addOn->id,
        'charge_model' => 'standard',
        'units' => 2,
        'properties' => ['amount' => '300'],
    ]];

    $result = CreateService::call(args: $args);

    expect($result->success())->toBeTrue();

    $fixedCharges = $result->plan->fixedCharges()->get();

    expect($fixedCharges)->toHaveCount(1)
        ->and($fixedCharges[0]->code)->toBe($addOn->code)
        ->and($fixedCharges[0]->add_on_id)->toBe($addOn->id);
});

it('persists bill_charges_monthly only for yearly and semiannual plans', function (): void {
    $organization = App\Models\Organization::factory()->create();

    $yearlyArgs = planCreateArgs();
    $yearlyArgs['organization_id'] = $organization->id;
    $yearlyArgs['interval'] = 'yearly';
    $yearlyArgs['bill_charges_monthly'] = true;

    $result = CreateService::call(args: $yearlyArgs);

    expect($result->success())->toBeTrue()
        ->and($result->plan->bill_charges_monthly)->toBeTrue();

    $monthlyArgs = planCreateArgs();
    $monthlyArgs['organization_id'] = $organization->id;
    $monthlyArgs['code'] = Str::uuid();
    $monthlyArgs['bill_charges_monthly'] = true;

    $monthlyResult = CreateService::call(args: $monthlyArgs);

    expect($monthlyResult->success())->toBeTrue()
        ->and($monthlyResult->plan->bill_charges_monthly)->toBeNull();
});

it('fails with a duplicate code among parents', function (): void {
    $organization = App\Models\Organization::factory()->create();

    Plan::factory()->create(['organization_id' => $organization->id, 'code' => 'dupe']);

    $args = planCreateArgs();
    $args['organization_id'] = $organization->id;
    $args['code'] = 'dupe';

    $result = CreateService::call(args: $args);

    expect($result->failure())->toBeTrue();

    $error = $result->getError();

    expect($error)->toBeInstanceOf(ValidationFailure::class)
        ->and($error->messages['code'])->toContain('value_already_exist');
});

it('creates a plan with the same code used by a deleted plan', function (): void {
    $organization = App\Models\Organization::factory()->create();

    Plan::factory()->create(['organization_id' => $organization->id, 'code' => 'recycled'])->delete();

    $args = planCreateArgs();
    $args['organization_id'] = $organization->id;
    $args['code'] = 'recycled';

    $result = CreateService::call(args: $args);

    expect($result->success())->toBeTrue();
});

it('fails with an invalid interval', function (): void {
    $args = planCreateArgs();
    $args['organization_id'] = App\Models\Organization::factory()->create()->id;
    $args['interval'] = 'biweekly';

    $result = CreateService::call(args: $args);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['interval'])->toContain('value_is_invalid');
});

it('fails when a blank interval is given', function (): void {
    // A blank interval keeps the historical "value_is_invalid" error, not
    // "value_is_mandatory" (plan.rb:56-58).
    $args = planCreateArgs();
    $args['organization_id'] = App\Models\Organization::factory()->create()->id;
    $args['interval'] = null;

    $result = CreateService::call(args: $args);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['interval'])->toContain('value_is_invalid');
});

it('fails when the nested charges reference unknown billable metrics', function (): void {
    $args = planCreateArgs();
    $args['organization_id'] = App\Models\Organization::factory()->create()->id;
    $args['charges'] = [[
        'billable_metric_id' => Str::uuid(),
        'charge_model' => 'standard',
        'properties' => ['amount' => '10'],
    ]];

    $result = CreateService::call(args: $args);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class);
});

it('fails when the nested charge properties are invalid', function (): void {
    $organization = App\Models\Organization::factory()->create();
    $metric = BillableMetric::factory()->create(['organization_id' => $organization->id]);

    $args = planCreateArgs();
    $args['organization_id'] = $organization->id;
    $args['charges'] = [[
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'properties' => ['amount' => '-10'],
    ]];

    $result = CreateService::call(args: $args);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['properties'])->toContain('invalid_amount');
});

it('does not create the plan when a nested charge fails', function (): void {
    $organization = App\Models\Organization::factory()->create();
    $metric = BillableMetric::factory()->create(['organization_id' => $organization->id]);

    $args = planCreateArgs();
    $args['organization_id'] = $organization->id;
    $args['charges'] = [[
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'properties' => ['amount' => 'foo'],
    ]];

    $result = CreateService::call(args: $args);

    expect($result->failure())->toBeTrue()
        ->and(Plan::query()->where('organization_id', $organization->id)->count())->toBe(0)
        ->and(Charge::query()->count())->toBe(0);
});
