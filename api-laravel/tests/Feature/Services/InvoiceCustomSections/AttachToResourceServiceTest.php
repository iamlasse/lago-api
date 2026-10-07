<?php

declare(strict_types=1);

use App\Models\Wallet;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Subscription;
use App\Support\CurrentContext;
use App\Models\InvoiceCustomSection;
use App\Services\InvoiceCustomSections\AttachToResourceService;

/**
 * Port of Rails' spec/services/invoice_custom_sections/
 * attach_to_resource_service_spec.rb — section attachable / skippable across
 * Subscription and Wallet resources (WalletTransaction covered in the
 * paid-credit slice wiring), plus the codes/ids context split and the
 * replace-on-attach semantics.
 *
 * Ledger row: svc:InvoiceCustomSections.AttachToResourceService.
 */
function attachFixture(string $resourceClass): array
{
    $organization = Organization::factory()->create();
    $billingEntity = App\Models\BillingEntity::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'billing_entity_id' => $billingEntity->id,
    ]);

    $sections = [];
    foreach ([1, 2, 3] as $i) {
        $sections[$i] = InvoiceCustomSection::factory()->create([
            'organization_id' => $organization->id,
            'code' => "section_code_{$i}",
        ]);
    }

    $resource = match ($resourceClass) {
        'subscription' => Subscription::factory()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
        ]),
        'wallet' => Wallet::factory()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
        ]),
        default => throw new InvalidArgumentException($resourceClass),
    };

    return compact('organization', 'billingEntity', 'customer', 'sections', 'resource');
}

$attachable = function (string $resourceClass): void {
    $f = attachFixture($resourceClass);
    CurrentContext::$source = 'api';

    AttachToResourceService::call(resource: $f['resource'], params: [
        'invoice_custom_section' => ['invoice_custom_section_codes' => ['section_code_1', 'section_code_3']],
    ]);

    $f['resource']->refresh();

    expect($f['resource']->skip_invoice_custom_sections)->toBeFalse()
        ->and($f['resource']->appliedInvoiceCustomSections()->count())->toBe(2)
        ->and($f['resource']->appliedInvoiceCustomSections()->pluck('invoice_custom_section_id')->all())
        ->toContain($f['sections'][1]->id, $f['sections'][3]->id);
};

$skippable = function (string $resourceClass): void {
    $f = attachFixture($resourceClass);
    CurrentContext::$source = 'api';

    $result = AttachToResourceService::call(resource: $f['resource'], params: [
        'invoice_custom_section' => ['skip_invoice_custom_sections' => true],
    ]);

    $f['resource']->refresh();

    expect($result->success())->toBeTrue()
        ->and($f['resource']->skip_invoice_custom_sections)->toBeTrue()
        ->and($f['resource']->appliedInvoiceCustomSections()->count())->toBe(0);
};

it('subscription can attach sections', fn () => $attachable('subscription'))
    ->group('ledger:svc:InvoiceCustomSections.AttachToResourceService');
it('subscription can skip sections', fn () => $skippable('subscription'))
    ->group('ledger:svc:InvoiceCustomSections.AttachToResourceService');
it('wallet can attach sections', fn () => $attachable('wallet'))
    ->group('ledger:svc:InvoiceCustomSections.AttachToResourceService');
it('wallet can skip sections', fn () => $skippable('wallet'))
    ->group('ledger:svc:InvoiceCustomSections.AttachToResourceService');

it('does nothing without the invoice_custom_section param', function (): void {
    $f = attachFixture('subscription');
    CurrentContext::$source = 'api';

    $result = AttachToResourceService::call(resource: $f['resource'], params: []);

    expect($result->success())->toBeTrue()
        ->and($f['resource']->appliedInvoiceCustomSections()->count())->toBe(0);
})->group('ledger:svc:InvoiceCustomSections.AttachToResourceService');

it('skip flag true wipes previously attached sections', function (): void {
    $f = attachFixture('subscription');
    CurrentContext::$source = 'api';

    App\Models\SubscriptionAppliedInvoiceCustomSection::factory()->create([
        'subscription_id' => $f['resource']->id,
        'invoice_custom_section_id' => $f['sections'][1]->id,
        'organization_id' => $f['organization']->id,
    ]);

    $result = AttachToResourceService::call(resource: $f['resource'], params: [
        'invoice_custom_section' => ['skip_invoice_custom_sections' => true],
    ]);

    $f['resource']->refresh();

    expect($result->success())->toBeTrue()
        ->and($f['resource']->skip_invoice_custom_sections)->toBeTrue()
        ->and($f['resource']->appliedInvoiceCustomSections()->count())->toBe(0);
})->group('ledger:svc:InvoiceCustomSections.AttachToResourceService');

it('without skip flag, a previously skipped resource stays untouched', function (): void {
    $f = attachFixture('subscription');
    CurrentContext::$source = 'api';
    $f['resource']->update(['skip_invoice_custom_sections' => true]);

    AttachToResourceService::call(resource: $f['resource'], params: [
        'invoice_custom_section' => ['invoice_custom_section_codes' => ['section_code_1']],
    ]);

    $f['resource']->refresh();

    expect($f['resource']->skip_invoice_custom_sections)->toBeTrue()
        ->and($f['resource']->appliedInvoiceCustomSections()->count())->toBe(0);
})->group('ledger:svc:InvoiceCustomSections.AttachToResourceService');

it('graphql context attaches by ids and replaces obsolete selections', function (): void {
    $f = attachFixture('subscription');
    CurrentContext::$source = 'graphql';

    $resource = $f['resource'];
    $resource->update(['skip_invoice_custom_sections' => false]);
    App\Models\SubscriptionAppliedInvoiceCustomSection::factory()->create([
        'subscription_id' => $resource->id,
        'invoice_custom_section_id' => $f['sections'][1]->id,
        'organization_id' => $f['organization']->id,
    ]);

    // Replace: keep section_3, drop section_1.
    AttachToResourceService::call(resource: $resource, params: [
        'invoice_custom_section' => ['invoice_custom_section_ids' => [$f['sections'][3]->id]],
    ]);

    $resource->refresh();

    $ids = $resource->appliedInvoiceCustomSections()->pluck('invoice_custom_section_id')->all();

    expect($resource->skip_invoice_custom_sections)->toBeFalse()
        ->and($ids)->toBe([$f['sections'][3]->id]);
})->group('ledger:svc:InvoiceCustomSections.AttachToResourceService');
