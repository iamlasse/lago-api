<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Organization;
use App\Models\BillingEntity;
use App\Models\InvoiceCustomSection;
use App\Enums\InvoiceCustomSectionType;
use App\Services\InvoiceCustomSections\CreateService;
use App\Services\InvoiceCustomSections\UpdateService;
use App\Services\InvoiceCustomSections\DestroyService;
use App\Services\InvoiceCustomSections\DeselectAllService;

/**
 * Ports of Rails' spec/services/invoice_custom_sections/
 * {create,update,destroy,deselect_all}_service_spec.rb.
 *
 * Ledger rows: svc:InvoiceCustomSections.CreateService,
 * svc:InvoiceCustomSections.UpdateService,
 * svc:InvoiceCustomSections.DestroyService,
 * svc:InvoiceCustomSections.DeselectAllService.
 */
function icsOrganization(): array
{
    $organization = Organization::factory()->create();
    $billingEntity = BillingEntity::factory()->create(['organization_id' => $organization->id]);

    return [$organization, $billingEntity];
}

it('creates an invoice custom section that belongs to the organization', function (): void {
    [$organization] = icsOrganization();

    $createParams = [
        'code' => 'test',
        'details' => 'This text will be displayed in the invoice',
        'display_name' => 'This will be the section title',
        'name' => 'my firsts section',
    ];

    $result = CreateService::call(organization: $organization, createParams: $createParams);

    expect($result->success())->toBeTrue()
        ->and($organization->invoiceCustomSections()->count())->toBe(1)
        ->and($result->invoice_custom_section->code)->toBe('test')
        ->and($result->invoice_custom_section->details)->toBe($createParams['details'])
        ->and($result->invoice_custom_section->display_name)->toBe($createParams['display_name'])
        ->and($result->invoice_custom_section->name)->toBe($createParams['name'])
        ->and($result->invoice_custom_section->section_type)->toBe(InvoiceCustomSectionType::Manual);
})->group('ledger:svc:InvoiceCustomSections.CreateService');

it('fails to create an invoice custom section without a code', function (): void {
    [$organization] = icsOrganization();

    $result = CreateService::call(organization: $organization, createParams: []);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe([
            'name' => ['value_is_mandatory'],
            'code' => ['value_is_mandatory'],
        ]);
})->group('ledger:svc:InvoiceCustomSections.CreateService');

it('updates the invoice custom section', function (): void {
    [$organization] = icsOrganization();
    $section = InvoiceCustomSection::factory()->create(['organization_id' => $organization->id]);

    $result = UpdateService::call(invoiceCustomSection: $section, updateParams: ['name' => 'Updated Name']);

    expect($result->success())->toBeTrue()
        ->and($result->invoice_custom_section->name)->toBe('Updated Name');
})->group('ledger:svc:InvoiceCustomSections.UpdateService');

it('fails updating with an invalid name', function (): void {
    [$organization] = icsOrganization();
    $section = InvoiceCustomSection::factory()->create(['organization_id' => $organization->id]);

    $result = UpdateService::call(invoiceCustomSection: $section, updateParams: ['name' => null]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['name' => ['value_is_mandatory']]);
})->group('ledger:svc:InvoiceCustomSections.UpdateService');

it('answers not found when updating a missing section', function (): void {
    $result = UpdateService::call(invoiceCustomSection: null, updateParams: ['name' => 'x']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toBe('invoice_custom_section_not_found');
})->group('ledger:svc:InvoiceCustomSections.UpdateService');

it('discards the invoice custom section and destroys all selections', function (): void {
    [$organization, $billingEntity] = icsOrganization();
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'billing_entity_id' => $billingEntity->id,
    ]);
    $section = InvoiceCustomSection::factory()->create(['organization_id' => $organization->id]);

    App\Models\BillingEntityAppliedInvoiceCustomSection::factory()->create([
        'organization_id' => $organization->id,
        'billing_entity_id' => $billingEntity->id,
        'invoice_custom_section_id' => $section->id,
    ]);
    App\Models\CustomerAppliedInvoiceCustomSection::factory()->create([
        'organization_id' => $organization->id,
        'billing_entity_id' => $billingEntity->id,
        'customer_id' => $customer->id,
        'invoice_custom_section_id' => $section->id,
    ]);

    $result = DestroyService::call(invoiceCustomSection: $section);

    expect($result->success())->toBeTrue();

    // Rails: be_discarded — soft delete on deleted_at.
    expect($section->refresh()->deleted_at)->not->toBeNull()
        ->and($billingEntity->appliedInvoiceCustomSections()->count())->toBe(0)
        ->and($customer->appliedInvoiceCustomSections()->count())->toBe(0);

    // The discarded section is out of the default scope.
    expect(InvoiceCustomSection::query()->whereKey($section->id)->exists())->toBeFalse();
})->group('ledger:svc:InvoiceCustomSections.DestroyService');

it('deselects the section for the billing entity and customer', function (): void {
    [$organization, $billingEntity] = icsOrganization();
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'billing_entity_id' => $billingEntity->id,
    ]);
    $section = InvoiceCustomSection::factory()->create(['organization_id' => $organization->id]);

    App\Models\BillingEntityAppliedInvoiceCustomSection::factory()->create([
        'organization_id' => $organization->id,
        'billing_entity_id' => $billingEntity->id,
        'invoice_custom_section_id' => $section->id,
    ]);
    App\Models\CustomerAppliedInvoiceCustomSection::factory()->create([
        'organization_id' => $organization->id,
        'billing_entity_id' => $billingEntity->id,
        'customer_id' => $customer->id,
        'invoice_custom_section_id' => $section->id,
    ]);

    $result = DeselectAllService::call(section: $section);

    expect($result->success())->toBeTrue()
        ->and($billingEntity->appliedInvoiceCustomSections()->count())->toBe(0)
        ->and($customer->appliedInvoiceCustomSections()->count())->toBe(0);
})->group('ledger:svc:InvoiceCustomSections.DeselectAllService');

it('succeeds deselecting a section that is not selected', function (): void {
    [$organization] = icsOrganization();
    $section = InvoiceCustomSection::factory()->create(['organization_id' => $organization->id]);

    $result = DeselectAllService::call(section: $section);

    expect($result->success())->toBeTrue();
})->group('ledger:svc:InvoiceCustomSections.DeselectAllService');

it('rejects a duplicated code within the organization', function (): void {
    [$organization] = icsOrganization();
    InvoiceCustomSection::factory()->create(['organization_id' => $organization->id, 'code' => 'dup']);

    $result = CreateService::call(organization: $organization, createParams: [
        'code' => 'dup',
        'name' => 'Another',
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['code' => ['value_already_exist']]);
})->group('ledger:svc:InvoiceCustomSections.CreateService');

it('allows the same code in a different organization', function (): void {
    [$organization] = icsOrganization();
    InvoiceCustomSection::factory()->create(['organization_id' => $organization->id, 'code' => 'dup']);

    $other = Organization::factory()->create();
    $result = CreateService::call(organization: $other, createParams: [
        'code' => 'dup',
        'name' => 'Another',
    ]);

    expect($result->success())->toBeTrue();
})->group('ledger:svc:InvoiceCustomSections.CreateService');
