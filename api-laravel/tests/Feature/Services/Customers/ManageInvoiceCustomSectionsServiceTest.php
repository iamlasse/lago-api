<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Organization;
use App\Models\BillingEntity;
use App\Models\InvoiceCustomSection;
use App\Services\Customers\ManageInvoiceCustomSectionsService;

/**
 * Port of Rails' spec/services/customers/manage_invoice_custom_sections_service_spec.rb.
 *
 * Ledger row: svc:Customers.ManageInvoiceCustomSectionsService.
 */
function micsFixture(): array
{
    $organization = Organization::factory()->create();
    $billingEntity = BillingEntity::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'billing_entity_id' => $billingEntity->id,
    ]);

    $sections = [];
    foreach ([1, 2] as $i) {
        $sections[$i] = InvoiceCustomSection::factory()->create([
            'organization_id' => $organization->id,
            'code' => "mics_code_{$i}",
        ]);
    }

    return compact('organization', 'billingEntity', 'customer', 'sections');
}

it('sets skip flag and clears all selections', function (): void {
    $f = micsFixture();

    App\Models\CustomerAppliedInvoiceCustomSection::factory()->create([
        'organization_id' => $f['organization']->id,
        'billing_entity_id' => $f['billingEntity']->id,
        'customer_id' => $f['customer']->id,
        'invoice_custom_section_id' => $f['sections'][1]->id,
    ]);

    $result = ManageInvoiceCustomSectionsService::call(
        customer: $f['customer'],
        skipInvoiceCustomSections: true,
    );

    expect($result->success())->toBeTrue()
        ->and($result->customer->skip_invoice_custom_sections)->toBeTrue()
        ->and($f['customer']->appliedInvoiceCustomSections()->count())->toBe(0);
})->group('ledger:svc:Customers.ManageInvoiceCustomSectionsService');

it('selects sections by ids and sets skip to false', function (): void {
    $f = micsFixture();

    $result = ManageInvoiceCustomSectionsService::call(
        customer: $f['customer'],
        skipInvoiceCustomSections: null,
        sectionIds: [(string) $f['sections'][2]->id],
    );

    expect($result->success())->toBeTrue()
        ->and($f['customer']->skip_invoice_custom_sections)->toBeFalse()
        ->and($f['customer']->selectedInvoiceCustomSections()->pluck('invoice_custom_sections.code')->all())
        ->toBe([$f['sections'][2]->code]);
})->group('ledger:svc:Customers.ManageInvoiceCustomSectionsService');

it('selects sections by codes', function (): void {
    $f = micsFixture();

    $result = ManageInvoiceCustomSectionsService::call(
        customer: $f['customer'],
        skipInvoiceCustomSections: null,
        sectionCodes: ['mics_code_1'],
    );

    expect($result->success())->toBeTrue()
        ->and($f['customer']->selectedInvoiceCustomSections()->pluck('code')->all())
        ->toBe([$f['sections'][1]->code]);
})->group('ledger:svc:Customers.ManageInvoiceCustomSectionsService');

it('rejects section ids and codes sent together', function (): void {
    $f = micsFixture();

    $result = ManageInvoiceCustomSectionsService::call(
        customer: $f['customer'],
        skipInvoiceCustomSections: null,
        sectionIds: ['x'],
        sectionCodes: ['y'],
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe([
            'invoice_custom_sections' => ['section_ids_and_section_codes_sent_together'],
        ]);
})->group('ledger:svc:Customers.ManageInvoiceCustomSectionsService');

it('rejects skip flag together with a selection', function (): void {
    $f = micsFixture();

    $result = ManageInvoiceCustomSectionsService::call(
        customer: $f['customer'],
        skipInvoiceCustomSections: true,
        sectionIds: ['x'],
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe([
            'invoice_custom_sections' => ['skip_sections_and_selected_ids_sent_together'],
        ]);
})->group('ledger:svc:Customers.ManageInvoiceCustomSectionsService');

it('replaces existing manual selection but keeps system-generated rows', function (): void {
    $f = micsFixture();

    $systemGenerated = InvoiceCustomSection::factory()
        ->systemGenerated()
        ->create(['organization_id' => $f['organization']->id]);

    App\Models\CustomerAppliedInvoiceCustomSection::factory()->create([
        'organization_id' => $f['organization']->id,
        'billing_entity_id' => $f['billingEntity']->id,
        'customer_id' => $f['customer']->id,
        'invoice_custom_section_id' => $systemGenerated->id,
    ]);

    $result = ManageInvoiceCustomSectionsService::call(
        customer: $f['customer'],
        skipInvoiceCustomSections: null,
        sectionIds: [(string) $f['sections'][1]->id],
    );

    expect($result->success())->toBeTrue();

    $selectedIds = $f['customer']->appliedInvoiceCustomSections()
        ->pluck('invoice_custom_section_id')
        ->sort()
        ->values()
        ->all();

    $expected = [$systemGenerated->id, $f['sections'][1]->id];
    sort($expected);

    expect($selectedIds)->toBe($expected);
})->group('ledger:svc:Customers.ManageInvoiceCustomSectionsService');

it('answers not found for a missing customer', function (): void {
    $result = ManageInvoiceCustomSectionsService::call(
        customer: null,
        skipInvoiceCustomSections: null,
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toBe('customer_not_found');
})->group('ledger:svc:Customers.ManageInvoiceCustomSectionsService');
