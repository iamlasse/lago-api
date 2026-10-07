<?php

declare(strict_types=1);

require_once __DIR__.'/../../AccountingCollectorsFixture.php';

use App\Models\Integrations\XeroIntegration;
use App\Models\IntegrationMappings\XeroMapping;
use App\Services\Integrations\Aggregator\BasePayload\Failure;
use App\Models\IntegrationCollectionMappings\XeroCollectionMapping;
use App\Services\Integrations\Aggregator\CreditNotes\Payloads\Xero;

/**
 * Port of Rails' spec/services/integrations/aggregator/credit_notes/
 * payloads/xero_spec.rb — the ACCRECCREDIT shape, the item_code rename and
 * the mapping resolution pinned end-to-end.
 */
it('builds the xero credit note body with the billable metric item_code end-to-end', function (): void {
    [, $customer, $invoice, $integration, $integrationCustomer] = accountingCollectorPayloadFixture(XeroIntegration::class);
    [, $creditNote, $creditNoteItem] = accountingChargeCreditNote($invoice->organization, $customer, $invoice);

    XeroMapping::factory()->forIntegration($integration)->forMappable('BillableMetric', $creditNoteItem->fee->charge->billableMetric)->create([
        'organization_id' => $integration->organization_id,
    ]);

    $body = (new Xero($integrationCustomer, $creditNote))->body();

    expect($body)->toHaveCount(1)
        ->and($body[0]['type'])->toBe('ACCRECCREDIT')
        ->and($body[0]['status'])->toBe('AUTHORISED')
        ->and($body[0]['number'])->toBe($creditNote->number)
        ->and($body[0]['currency'])->toBe($creditNote->total_amount_currency)
        ->and($body[0]['external_contact_id'])->toBe($integrationCustomer->external_customer_id)
        ->and($body[0]['fees'])->toHaveCount(1)
        ->and($body[0]['fees'][0]['item_code'])->toBe('xero-123')
        ->and($body[0]['fees'][0]['account_code'])->toBe('xero-code-1')
        ->and($body[0]['fees'][0])->not->toHaveKey('external_id')
        ->and($body[0]['fees'][0]['units'])->toBe(1)
        ->and($body[0]['fees'][0]['precise_unit_amount'])->toBe('2.12')
        ->and($body[0]['fees'][0]['taxes_amount_cents'])->toBe('0');
});

it('renames the coupon line external_id to item_code for xero', function (): void {
    [$organization, $customer, $invoice, $integration, $integrationCustomer] = accountingCollectorPayloadFixture(XeroIntegration::class);
    [, $creditNote] = accountingChargeCreditNote($organization, $customer, $invoice);

    $creditNote->coupons_adjustment_amount_cents = 50;
    $creditNote->save();

    // The fee line itself needs its mapping for body() to build.
    $metric = $creditNote->items->first()->fee->charge->billableMetric;
    XeroMapping::factory()->forIntegration($integration)->forMappable('BillableMetric', $metric)->create([
        'organization_id' => $integration->organization_id,
    ]);

    XeroCollectionMapping::factory()->withMappingType('coupon')->forIntegration($integration)->create([
        'organization_id' => $organization->id,
    ]);

    $fees = (new Xero($integrationCustomer, $creditNote))->body()[0]['fees'];

    expect($fees)->toHaveCount(2)
        ->and($fees[1]['description'])->toBe('Coupons')
        ->and($fees[1]['item_code'])->toBe('xero-123')
        ->and($fees[1]['account_code'])->toBe('xero-code-1')
        ->and($fees[1]['precise_unit_amount'])->toBe('-0.5')
        ->and($fees[1]['taxes_amount_cents'])->toBe(0)
        ->and($fees[1])->not->toHaveKey('external_id');
});

it('adjusts the first positively-taxed item with the tax rounding remainder', function (): void {
    [$organization, $customer, $invoice, $integration, $integrationCustomer] = accountingCollectorPayloadFixture(XeroIntegration::class);
    [$metric, $creditNote] = accountingChargeCreditNote($organization, $customer, $invoice, 212, [
        'taxes_rate' => 20,
        'taxes_amount_cents' => 50,
    ]);

    XeroMapping::factory()->forIntegration($integration)->forMappable('BillableMetric', $metric)->create([
        'organization_id' => $integration->organization_id,
    ]);

    $fees = (new Xero($integrationCustomer, $creditNote))->body()[0]['fees'];

    // 212 * 20% = 42.4 → the item line carries 42.4, the credit note's
    // taxes_amount_cents is 50 → the remainder lands on the first item.
    expect((float) $fees[0]['taxes_amount_cents'])->toEqual(50.0);
});

it('emits a zero-unit line for a zero-amount credit note item', function (): void {
    [$organization, $customer, $invoice, $integration, $integrationCustomer] = accountingCollectorPayloadFixture(XeroIntegration::class);
    [$metric, $creditNote] = accountingChargeCreditNote($organization, $customer, $invoice, 0);

    XeroMapping::factory()->forIntegration($integration)->forMappable('BillableMetric', $metric)->create([
        'organization_id' => $integration->organization_id,
    ]);

    $fees = (new Xero($integrationCustomer, $creditNote))->body()[0]['fees'];

    expect($fees[0]['units'])->toBe(0);
});

it('fails with invalid_mapping when the fee has no mapping', function (): void {
    [$organization, $customer, $invoice, $integration, $integrationCustomer] = accountingCollectorPayloadFixture(XeroIntegration::class);
    [, $creditNote] = accountingChargeCreditNote($organization, $customer, $invoice);

    new Xero($integrationCustomer, $creditNote)->body();
})->throws(Failure::class, 'invalid_mapping');
