<?php

declare(strict_types=1);

use App\Models\AddOn;
use App\Models\Customer;
use App\Enums\InvoiceType;
use App\Enums\InvoiceStatus;
use App\Jobs\SendWebhookJob;
use App\Models\Organization;
use App\Support\CurrentContext;
use App\Enums\InvoicePaymentStatus;
use Illuminate\Support\Facades\Queue;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ValidationFailure;
use App\Services\Invoices\CreateOneOffService;

uses()->group('ledger:svc:Invoices.CreateOneOffService');

/**
 * Port of Rails' spec/services/invoices/create_one_off_service_spec.rb.
 */
function oneOffTax(Organization $organization, $billingEntity): object
{
    $tax = App\Models\Tax::factory()->create(['organization_id' => $organization->id, 'rate' => 20]);

    Illuminate\Support\Facades\DB::table('billing_entities_taxes')->insert([
        'id' => (string) Illuminate\Support\Str::uuid(),
        'billing_entity_id' => $billingEntity->id,
        'tax_id' => $tax->id,
        'organization_id' => $organization->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $tax;
}

function oneOffContext(callable $scenario): void
{
    CurrentContext::$source = 'api';

    try {
        $scenario();
    } finally {
        CurrentContext::$source = null;
    }
}

it('creates a one-off invoice', function (): void {
    oneOffContext(function (): void {
        $organization = Organization::factory()->create();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        oneOffTax($organization, $customer->billingEntity);

        $addOnFirst = AddOn::factory()->create(['organization_id' => $organization->id]);
        $addOnSecond = AddOn::factory()->create(['organization_id' => $organization->id, 'amount_cents' => 400]);

        $timestamp = now()->startOfMonth()->getTimestamp();

        $result = CreateOneOffService::call(
            customer: $customer,
            currency: 'EUR',
            fees: [
                ['add_on_code' => $addOnFirst->code, 'unit_amount_cents' => 1200, 'units' => 2, 'description' => 'desc-123'],
                ['add_on_code' => $addOnSecond->code],
            ],
            timestamp: $timestamp,
        );

        expect($result->success())->toBeTrue();

        $invoice = $result->invoice;

        expect($invoice->issuing_date->toDateString())->toBe(now()->startOfMonth()->toDateString());
        expect($invoice->typeEnum())->toBe(InvoiceType::OneOff);
        expect($invoice->paymentStatusEnum())->toBe(InvoicePaymentStatus::Pending);
        expect($invoice->fees()->where('fee_type', 1)->count())->toBe(2);
        expect($invoice->fees()->pluck('description')->all())->toContain('desc-123');

        expect($invoice->currency)->toBe('EUR');
        expect($invoice->fees_amount_cents)->toBe(2800);
        expect($invoice->taxes_amount_cents)->toBe(560);
        expect($invoice->taxes_rate)->toBe(20.0);
        expect($invoice->appliedTaxes()->count())->toBe(1);
        expect($invoice->total_amount_cents)->toBe(3360);
        expect($invoice->voided_invoice_id)->toBeNull();
        expect($invoice->statusEnum())->toBe(InvoiceStatus::Finalized);
    });
});

it('stamps the voided invoice id when passed', function (): void {
    oneOffContext(function (): void {
        $organization = Organization::factory()->create();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

        $voidedInvoiceId = (string) Illuminate\Support\Str::uuid();

        $result = CreateOneOffService::call(
            customer: $customer,
            currency: 'EUR',
            fees: [['add_on_code' => $addOn->code]],
            timestamp: now()->getTimestamp(),
            voidedInvoiceId: $voidedInvoiceId,
        );

        expect($result->success())->toBeTrue();
        expect($result->invoice->voided_invoice_id)->toBe($voidedInvoiceId);
    });
});

it('stamps the purchase order number normalized', function (): void {
    oneOffContext(function (): void {
        $organization = Organization::factory()->create();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

        $result = CreateOneOffService::call(
            customer: $customer,
            currency: 'EUR',
            fees: [['add_on_code' => $addOn->code]],
            timestamp: now()->getTimestamp(),
            purchaseOrderNumber: '  PO-12345  ',
        );

        expect($result->success())->toBeTrue();
        expect($result->invoice->purchase_order_number)->toBe('PO-12345');
    });
});

it('creates a payment_succeeded invoice when the amount is zero', function (): void {
    oneOffContext(function (): void {
        $organization = Organization::factory()->create();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        oneOffTax($organization, $customer->billingEntity);
        $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

        $result = CreateOneOffService::call(
            customer: $customer,
            currency: 'EUR',
            fees: [['add_on_code' => $addOn->code, 'unit_amount_cents' => 0, 'units' => 2]],
            timestamp: now()->getTimestamp(),
        );

        expect($result->success())->toBeTrue();
        expect($result->invoice->paymentStatusEnum())->toBe(InvoicePaymentStatus::Succeeded);
        expect($result->invoice->fees_amount_cents)->toBe(0);
        expect($result->invoice->taxes_amount_cents)->toBe(0);
        expect($result->invoice->taxes_rate)->toBe(20.0);
        expect($result->invoice->total_amount_cents)->toBe(0);
        expect($result->invoice->statusEnum())->toBe(InvoiceStatus::Finalized);
    });
});

it('assigns the issuing date in the customer timezone', function (): void {
    oneOffContext(function (): void {
        $organization = Organization::factory()->create();
        $customer = Customer::factory()->create(['organization_id' => $organization->id, 'timezone' => 'America/Los_Angeles']);
        $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

        $timestamp = Carbon\CarbonImmutable::parse('2022-11-25 01:00:00', 'UTC')->getTimestamp();

        $result = CreateOneOffService::call(
            customer: $customer,
            currency: 'EUR',
            fees: [['add_on_code' => $addOn->code]],
            timestamp: $timestamp,
        );

        expect($result->success())->toBeTrue();
        expect($result->invoice->issuing_date->toDateString())->toBe('2022-11-24');
    });
});

it('creates the invoice when the currency does not match the customer preference', function (): void {
    oneOffContext(function (): void {
        $organization = Organization::factory()->create();
        $customer = Customer::factory()->create(['organization_id' => $organization->id, 'currency' => 'EUR']);
        $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

        $result = CreateOneOffService::call(
            customer: $customer,
            currency: 'NOK',
            fees: [['add_on_code' => $addOn->code]],
            timestamp: now()->getTimestamp(),
        );

        expect($result->success())->toBeTrue();
        expect($result->invoice->currency)->toBe('NOK');
    });
});

it('fails with a currency validation failure when no currency resolves', function (): void {
    oneOffContext(function (): void {
        $organization = Organization::factory()->create();
        $customer = Customer::factory()->create(['organization_id' => $organization->id, 'currency' => null]);
        $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

        $result = CreateOneOffService::call(
            customer: $customer,
            currency: null,
            fees: [['add_on_code' => $addOn->code]],
            timestamp: now()->getTimestamp(),
        );

        expect($result->success())->toBeFalse();
        expect($result->getError())->toBeInstanceOf(ValidationFailure::class);
        expect($result->getError()->messages)->toHaveKey('currency');
        expect($result->getError()->messages['currency'])->toContain('value_is_mandatory');
    });
});

it('returns a not found failure when the customer is missing', function (): void {
    $result = CreateOneOffService::call(
        customer: null,
        currency: 'EUR',
        fees: [['add_on_code' => 'x']],
        timestamp: now()->getTimestamp(),
    );

    expect($result->success())->toBeFalse();
    expect($result->getError())->toBeInstanceOf(NotFoundFailure::class);
});

it('returns a not found failure when the fees payload is blank', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $result = CreateOneOffService::call(
        customer: $customer,
        currency: 'EUR',
        fees: [],
        timestamp: now()->getTimestamp(),
    );

    expect($result->success())->toBeFalse();
    expect($result->getError())->toBeInstanceOf(NotFoundFailure::class);
});

it('returns a validation failure for an invalid payment method type', function (): void {
    oneOffContext(function (): void {
        $organization = Organization::factory()->create();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

        $result = CreateOneOffService::call(
            customer: $customer,
            currency: 'EUR',
            fees: [['add_on_code' => $addOn->code]],
            timestamp: now()->getTimestamp(),
            paymentMethodParams: ['payment_method_id' => 'pm-1', 'payment_method_type' => 'invalid'],
        );

        expect($result->success())->toBeFalse();
        expect($result->getError())->toBeInstanceOf(ValidationFailure::class);
        expect($result->getError()->messages['payment_method'] ?? null)->toBe(['invalid_payment_method']);
    });
});

it('resolves the billing entity by id and code', function (): void {
    oneOffContext(function (): void {
        $organization = Organization::factory()->create();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $otherEntity = App\Models\BillingEntity::factory()->create(['organization_id' => $organization->id]);
        $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

        $byId = CreateOneOffService::call(
            customer: $customer,
            currency: 'EUR',
            fees: [['add_on_code' => $addOn->code]],
            timestamp: now()->getTimestamp(),
            billingEntityId: $otherEntity->id,
        );

        expect($byId->success())->toBeTrue();
        expect($byId->invoice->billing_entity_id)->toBe($otherEntity->id);

        $byCode = CreateOneOffService::call(
            customer: $customer,
            currency: 'EUR',
            fees: [['add_on_code' => $addOn->code]],
            timestamp: now()->getTimestamp(),
            billingEntityCode: $otherEntity->code,
        );

        expect($byCode->success())->toBeTrue();
        expect($byCode->invoice->billing_entity_id)->toBe($otherEntity->id);

        $fallback = CreateOneOffService::call(
            customer: $customer,
            currency: 'EUR',
            fees: [['add_on_code' => $addOn->code]],
            timestamp: now()->getTimestamp(),
        );

        expect($fallback->success())->toBeTrue();
        expect($fallback->invoice->billing_entity_id)->toBe($customer->billing_entity_id);
    });
});

it('returns a not found failure for an unknown billing entity', function (): void {
    oneOffContext(function (): void {
        $organization = Organization::factory()->create();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

        $result = CreateOneOffService::call(
            customer: $customer,
            currency: 'EUR',
            fees: [['add_on_code' => $addOn->code]],
            timestamp: now()->getTimestamp(),
            billingEntityCode: 'unknown_code',
        );

        expect($result->success())->toBeFalse();
        expect($result->getError())->toBeInstanceOf(NotFoundFailure::class);
    });
});

it('returns a not found failure when an add-on code is invalid', function (): void {
    oneOffContext(function (): void {
        $organization = Organization::factory()->create();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

        $result = CreateOneOffService::call(
            customer: $customer,
            currency: 'EUR',
            fees: [
                ['add_on_code' => $addOn->code, 'unit_amount_cents' => 1200, 'units' => 2],
                ['add_on_code' => 'invalid'],
            ],
            timestamp: now()->getTimestamp(),
        );

        expect($result->success())->toBeFalse();
        expect($result->getError())->toBeInstanceOf(NotFoundFailure::class);
    });
});

it('does not find a soft-deleted add-on by default but bills it with discarded add-ons', function (): void {
    oneOffContext(function (): void {
        $organization = Organization::factory()->create();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);
        $addOn->delete();

        $result = CreateOneOffService::call(
            customer: $customer,
            currency: 'EUR',
            fees: [['add_on_code' => $addOn->code]],
            timestamp: now()->getTimestamp(),
        );

        expect($result->success())->toBeFalse();

        $result = CreateOneOffService::call(
            customer: $customer,
            currency: 'EUR',
            fees: [['add_on_code' => $addOn->code]],
            timestamp: now()->getTimestamp(),
            withDiscardedAddOns: true,
        );

        expect($result->success())->toBeTrue();
        expect($result->invoice->fees()->where('fee_type', 1)->count())->toBe(1);
    });
});

it('enqueues the one-off created webhook after the invoice is created', function (): void {
    Queue::fake();

    oneOffContext(function (): void {
        $organization = Organization::factory()->create();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

        $result = CreateOneOffService::call(
            customer: $customer,
            currency: 'EUR',
            fees: [['add_on_code' => $addOn->code]],
            timestamp: now()->getTimestamp(),
        );

        expect($result->success())->toBeTrue();
        Queue::assertPushed(SendWebhookJob::class);
    });
});

it('applies payload tax codes to the fees', function (): void {
    oneOffContext(function (): void {
        $organization = Organization::factory()->create();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $tax = App\Models\Tax::factory()->create(['organization_id' => $organization->id, 'rate' => 10]);
        $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

        $result = CreateOneOffService::call(
            customer: $customer,
            currency: 'EUR',
            fees: [['add_on_code' => $addOn->code, 'unit_amount_cents' => 1000, 'tax_codes' => [$tax->code]]],
            timestamp: now()->getTimestamp(),
        );

        expect($result->success())->toBeTrue();
        expect($result->invoice->taxes_amount_cents)->toBe(100);
        expect($result->invoice->appliedTaxes()->count())->toBe(1);
    });
});

it('rejects invalid fee boundaries', function (): void {
    oneOffContext(function (): void {
        $organization = Organization::factory()->create();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

        $result = CreateOneOffService::call(
            customer: $customer,
            currency: 'EUR',
            fees: [[
                'add_on_code' => $addOn->code,
                'from_datetime' => '2024-02-10T00:00:00Z',
                'to_datetime' => '2024-02-01T00:00:00Z',
            ]],
            timestamp: now()->getTimestamp(),
        );

        expect($result->success())->toBeFalse();
        expect($result->getError())->toBeInstanceOf(ValidationFailure::class);
    });
});
