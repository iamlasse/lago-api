<?php

declare(strict_types=1);

use App\Models\AppliedInvoiceCustomSection;
use App\Models\BillingEntity;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceCustomSection;
use App\Models\Organization;
use App\Services\Invoices\ApplyInvoiceCustomSectionsService;

/**
 * Port of Rails' spec/services/invoices/apply_invoice_custom_sections_service_spec.rb.
 *
 * Ledger row: svc:Invoices.ApplyInvoiceCustomSectionsService.
 */
function aicsFixture(): array
{
    $organization = Organization::factory()->create();
    $billingEntity = BillingEntity::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'billing_entity_id' => $billingEntity->id,
    ]);
    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'billing_entity_id' => $billingEntity->id,
    ]);

    $sections = [];
    foreach ([1, 2, 3] as $i) {
        $sections[$i] = InvoiceCustomSection::factory()->create([
            'organization_id' => $organization->id,
            'code' => "aics_code_{$i}",
        ]);
    }

    return compact('organization', 'billingEntity', 'customer', 'invoice', 'sections');
}

it('does not apply any custom sections when the customer skips them', function (): void {
    $f = aicsFixture();
    $f['customer']->update(['skip_invoice_custom_sections' => true]);

    $result = ApplyInvoiceCustomSectionsService::call(invoice: $f['invoice']);

    expect($result->success())->toBeTrue()
        ->and($result->applied_sections)->toBeEmpty()
        ->and(AppliedInvoiceCustomSection::query()->where('invoice_id', $f['invoice']->id)->count())->toBe(0);
})->group('ledger:svc:Invoices.ApplyInvoiceCustomSectionsService');

it('does not apply sections from another billing entity', function (): void {
    $f = aicsFixture();

    $otherBe = BillingEntity::factory()->create(['organization_id' => $f['organization']->id]);
    \App\Models\BillingEntityAppliedInvoiceCustomSection::factory()->create([
        'organization_id' => $f['organization']->id,
        'billing_entity_id' => $otherBe->id,
        'invoice_custom_section_id' => $f['sections'][1]->id,
    ]);

    // Customer's billing entity has no sections, customer has none selected → none.
    $result = ApplyInvoiceCustomSectionsService::call(invoice: $f['invoice']);

    expect($result->success())->toBeTrue()
        ->and(AppliedInvoiceCustomSection::query()->where('invoice_id', $f['invoice']->id)->count())->toBe(0);
})->group('ledger:svc:Invoices.ApplyInvoiceCustomSectionsService');

it('applies the customer manually selected sections with copied content', function (): void {
    $f = aicsFixture();

    \App\Models\CustomerAppliedInvoiceCustomSection::factory()->create([
        'organization_id' => $f['organization']->id,
        'billing_entity_id' => $f['billingEntity']->id,
        'customer_id' => $f['customer']->id,
        'invoice_custom_section_id' => $f['sections'][3]->id,
    ]);

    $result = ApplyInvoiceCustomSectionsService::call(invoice: $f['invoice']);

    expect($result->success())->toBeTrue();

    $sections = AppliedInvoiceCustomSection::query()->where('invoice_id', $f['invoice']->id)->get();

    expect($sections->pluck('code')->all())->toBe([$f['sections'][3]->code])
        ->and($sections->pluck('details')->all())->toBe([$f['sections'][3]->details])
        ->and($sections->pluck('display_name')->all())->toBe([$f['sections'][3]->display_name])
        ->and($sections->pluck('name')->all())->toBe([$f['sections'][3]->name]);
})->group('ledger:svc:Invoices.ApplyInvoiceCustomSectionsService');

it('inherits the billing entity sections when the customer has none', function (): void {
    $f = aicsFixture();

    foreach ([1, 2] as $i) {
        \App\Models\BillingEntityAppliedInvoiceCustomSection::factory()->create([
            'organization_id' => $f['organization']->id,
            'billing_entity_id' => $f['billingEntity']->id,
            'invoice_custom_section_id' => $f['sections'][$i]->id,
        ]);
    }

    $result = ApplyInvoiceCustomSectionsService::call(invoice: $f['invoice']);

    $codes = AppliedInvoiceCustomSection::query()->where('invoice_id', $f['invoice']->id)
        ->orderBy('code')->pluck('code')->all();

    expect($result->success())->toBeTrue()
        ->and($codes)->toBe([$f['sections'][1]->code, $f['sections'][2]->code]);
})->group('ledger:svc:Invoices.ApplyInvoiceCustomSectionsService');

it('single resource without its own sections falls back to customer sections', function (): void {
    $f = aicsFixture();
    $subscription = \App\Models\Subscription::factory()->create([
        'organization_id' => $f['organization']->id,
        'customer_id' => $f['customer']->id,
    ]);

    \App\Models\CustomerAppliedInvoiceCustomSection::factory()->create([
        'organization_id' => $f['organization']->id,
        'billing_entity_id' => $f['billingEntity']->id,
        'customer_id' => $f['customer']->id,
        'invoice_custom_section_id' => $f['sections'][3]->id,
    ]);

    $result = ApplyInvoiceCustomSectionsService::call(
        invoice: $f['invoice'],
        resources: [$subscription],
    );

    $codes = AppliedInvoiceCustomSection::query()->where('invoice_id', $f['invoice']->id)
        ->pluck('code')->all();

    expect($result->success())->toBeTrue()
        ->and($codes)->toBe([$f['sections'][3]->code]);
})->group('ledger:svc:Invoices.ApplyInvoiceCustomSectionsService');

it('resource with sections overrides customer and billing entity selection', function (): void {
    $f = aicsFixture();
    $subscription = \App\Models\Subscription::factory()->create([
        'organization_id' => $f['organization']->id,
        'customer_id' => $f['customer']->id,
    ]);

    foreach ([1, 2] as $i) {
        \App\Models\BillingEntityAppliedInvoiceCustomSection::factory()->create([
            'organization_id' => $f['organization']->id,
            'billing_entity_id' => $f['billingEntity']->id,
            'invoice_custom_section_id' => $f['sections'][$i]->id,
        ]);
    }
    \App\Models\SubscriptionAppliedInvoiceCustomSection::factory()->create([
        'organization_id' => $f['organization']->id,
        'subscription_id' => $subscription->id,
        'invoice_custom_section_id' => $f['sections'][3]->id,
    ]);

    $result = ApplyInvoiceCustomSectionsService::call(
        invoice: $f['invoice'],
        resources: [$subscription],
    );

    $codes = AppliedInvoiceCustomSection::query()->where('invoice_id', $f['invoice']->id)
        ->pluck('code')->all();

    expect($result->success())->toBeTrue()
        ->and($codes)->toBe([$f['sections'][3]->code]);
})->group('ledger:svc:Invoices.ApplyInvoiceCustomSectionsService');

it('skipped resource among participants does not drive selection', function (): void {
    $f = aicsFixture();
    $skippedSub = \App\Models\Subscription::factory()->create([
        'organization_id' => $f['organization']->id,
        'customer_id' => $f['customer']->id,
        'skip_invoice_custom_sections' => true,
    ]);

    // The only participating resource set is empty → treated as no-resource
    // call → falls back to the customer chain (none selected, no BE sections
    // → nothing applies except system-generated).
    $result = ApplyInvoiceCustomSectionsService::call(
        invoice: $f['invoice'],
        resources: [$skippedSub],
    );

    expect($result->success())->toBeTrue()
        ->and(AppliedInvoiceCustomSection::query()->where('invoice_id', $f['invoice']->id)->count())->toBe(0);
})->group('ledger:svc:Invoices.ApplyInvoiceCustomSectionsService');

it('explicit custom section ids apply directly and union system-generated', function (): void {
    $f = aicsFixture();
    $customer = $f['customer'];
    $customer->update(['skip_invoice_custom_sections' => true]);

    $sys = InvoiceCustomSection::factory()->systemGenerated()->create([
        'organization_id' => $f['organization']->id,
    ]);
    \App\Models\CustomerAppliedInvoiceCustomSection::factory()->create([
        'organization_id' => $f['organization']->id,
        'billing_entity_id' => $f['billingEntity']->id,
        'customer_id' => $customer->id,
        'invoice_custom_section_id' => $sys->id,
    ]);

    $result = ApplyInvoiceCustomSectionsService::call(
        invoice: $f['invoice'],
        customSectionIds: [(string) $f['sections'][3]->id],
    );

    $codes = AppliedInvoiceCustomSection::query()->where('invoice_id', $f['invoice']->id)
        ->orderBy('code')->pluck('code')->all();

    expect($result->success())->toBeTrue()
        ->and($codes)->toBe([$f['sections'][3]->code, $sys->code]);
})->group('ledger:svc:Invoices.ApplyInvoiceCustomSectionsService');
