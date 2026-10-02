<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Charge;
use App\Enums\ChargeModel;
use App\Enums\PlanInterval;
use App\Models\BillableMetric;

/**
 * Ports of the model-level validations and helpers of app/models/plan.rb and
 * app/models/charge.rb (spec/models/{plan,charge}_spec.rb highlights).
 */
function planForValidation(array $attributes = []): Plan
{
    return new Plan([
        'organization_id' => App\Models\Organization::factory()->create()->id,
        'name' => 'Plan',
        'code' => 'plan-code',
        'interval' => 'monthly',
        'amount_cents' => 100,
        'amount_currency' => 'EUR',
        'pay_in_advance' => false,
        ...$attributes,
    ]);
}

it('validates a well-formed plan', function () {
    expect(planForValidation()->validateAttributes())->toBe([]);
});

it('requires name, code, amount_cents and pay_in_advance', function () {
    $plan = new Plan(['organization_id' => 1]);

    $errors = $plan->validateAttributes();

    expect($errors['name'])->toBe(['value_is_mandatory'])
        ->and($errors['code'])->toBe(['value_is_mandatory'])
        ->and($errors['amount_cents'])->toBe(['value_is_mandatory'])
        ->and($errors['interval'])->toBe(['value_is_invalid']);
});

it('rejects a pay_in_advance that is not a boolean', function () {
    // Rails: validates :pay_in_advance, inclusion: {in: [true, false]} — nil
    // is invalid (AR would carry the schema default false, but an explicit
    // nil assignment is a validation error).
    $plan = planForValidation();
    $plan->pay_in_advance = null;
    $plan->offsetUnset('pay_in_advance');

    expect($plan->validateAttributes()['pay_in_advance'] ?? null)->toBe(['value_is_invalid']);
});

it('validates the amount currency against the ISO list', function () {
    $plan = planForValidation(['amount_currency' => 'XXX']);

    expect($plan->validateAttributes()['amount_currency'] ?? null)->toBe(['value_is_invalid']);

    $valid = planForValidation(['amount_currency' => 'USD']);

    expect($valid->validateAttributes())->toBe([]);
});

it('computes yearly_amount_cents for upgrade/downgrade comparisons', function () {
    expect(planForValidation(['interval' => 'yearly', 'amount_cents' => 1200])->yearlyAmountCents())->toBe(1200)
        ->and(planForValidation(['interval' => 'monthly', 'amount_cents' => 100])->yearlyAmountCents())->toBe(1200)
        ->and(planForValidation(['interval' => 'quarterly', 'amount_cents' => 300])->yearlyAmountCents())->toBe(1200)
        ->and(planForValidation(['interval' => 'semiannual', 'amount_cents' => 600])->yearlyAmountCents())->toBe(1200)
        ->and(planForValidation(['interval' => 'weekly', 'amount_cents' => 23])->yearlyAmountCents())->toBe(1196);
});

it('reports child plans and does not validate code uniqueness for them', function () {
    $parent = Plan::factory()->create(['code' => 'parent-code']);

    $child = new Plan([
        'organization_id' => $parent->organization_id,
        'name' => 'Child',
        'code' => 'parent-code',
        'interval' => 'monthly',
        'amount_cents' => 100,
        'amount_currency' => 'EUR',
        'pay_in_advance' => false,
        'parent_id' => $parent->id,
    ]);

    expect($child->isChild())->toBeTrue()
        ->and($parent->isParent())->toBeTrue()
        ->and($child->validateAttributes())->toBe([]);
});

it('knows charge model enum positions', function () {
    expect(ChargeModel::from(0)->label())->toBe('standard')
        ->and(ChargeModel::from(4)->label())->toBe('volume')
        ->and(ChargeModel::from(5)->label())->toBe('graduated_percentage')
        ->and(ChargeModel::from(7)->label())->toBe('dynamic')
        ->and(PlanInterval::from(4)->label())->toBe('semiannual');
});

// -- Charge model validations (non-premium matrix) ----------------------------

function unsavedCharge(array $attributes, array $properties): Charge
{
    $plan = Plan::factory()->create();
    $metric = BillableMetric::factory()->create(['organization_id' => $plan->organization_id]);

    return new Charge([
        'organization_id' => $plan->organization_id,
        'plan_id' => $plan->id,
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'code' => 'charge-code',
        'properties' => $properties,
        ...$attributes,
    ]);
}

it('validates a well-formed charge', function () {
    expect(unsavedCharge([], ['amount' => '10'])->validateAttributes())->toBe([]);
});

it('rejects a negative min_amount_cents', function () {
    $charge = unsavedCharge(['min_amount_cents' => -5], ['amount' => '10']);

    expect($charge->validateAttributes()['min_amount_cents'] ?? null)->toBe(['value_is_out_of_range']);
});

it('rejects a positive min_amount_cents on pay_in_advance charges', function () {
    $charge = unsavedCharge(['pay_in_advance' => true, 'min_amount_cents' => 5], ['amount' => '10']);

    expect($charge->validateAttributes()['min_amount_cents'] ?? null)->toBe(['not_compatible_with_pay_in_advance']);
});

it('rejects invoiceable false on pay-in-arrears charges', function () {
    $charge = unsavedCharge(['invoiceable' => false], ['amount' => '10']);

    expect($charge->validateAttributes()['invoiceable'] ?? null)->toBe(['must_be_true_unless_pay_in_advance']);
});

it('rejects regroup_paid_fees on invoiceable charges', function () {
    $charge = unsavedCharge(['regroup_paid_fees' => 0], ['amount' => '10']);

    expect($charge->validateAttributes()['regroup_paid_fees'] ?? null)
        ->toBe(['only_compatible_with_pay_in_advance_and_non_invoiceable']);
});

it('accepts regroup_paid_fees on pay_in_advance non-invoiceable charges', function () {
    $charge = unsavedCharge(['pay_in_advance' => true, 'invoiceable' => false, 'regroup_paid_fees' => 0], ['amount' => '10']);

    expect($charge->validateAttributes()['regroup_paid_fees'] ?? null)->toBeNull();
});

it('rejects a charge with an unknown charge model position', function () {
    $charge = unsavedCharge([], ['amount' => '10']);
    $charge->charge_model = 99;

    expect($charge->validateAttributes()['charge_model'] ?? null)->toBe(['value_is_invalid']);
});

it('requires a billable metric', function () {
    $charge = unsavedCharge([], ['amount' => '10']);
    $charge->billable_metric_id = null;

    expect($charge->validateAttributes()['billable_metric'] ?? null)->toBe(['value_is_mandatory']);
});

it('flattens the property validator errors under properties', function () {
    $charge = unsavedCharge([], ['amount' => 'foo']);

    expect($charge->validateAttributes()['properties'] ?? null)->toBe(['invalid_amount']);
});

it('accepts a dynamic charge on a sum aggregation metric', function () {
    $charge = unsavedCharge(['charge_model' => 'dynamic'], []);

    expect($charge->validateAttributes())->toBe([]);
});

it('rejects a custom charge on a non-custom aggregation metric', function () {
    $charge = unsavedCharge(['charge_model' => 'custom'], []);

    expect($charge->validateAttributes()['charge_model'] ?? null)->toBe(['invalid_aggregation_type_or_charge_model']);

    $custom = BillableMetric::factory()->customAgg()->create([
        'organization_id' => $charge->organization_id,
    ]);
    $charge->billable_metric_id = $custom->id;

    expect($charge->validateAttributes())->toBe([]);
});
