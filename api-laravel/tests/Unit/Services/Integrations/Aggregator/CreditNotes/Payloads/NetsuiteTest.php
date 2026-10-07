<?php

declare(strict_types=1);

require_once __DIR__.'/../../AccountingCollectorsFixture.php';

use App\Models\Fee;
use App\Models\Plan;
use App\Models\AddOn;
use App\Enums\FeeType;
use App\Models\FixedCharge;
use App\Models\Integrations\NetsuiteIntegration;
use App\Models\IntegrationMappings\NetsuiteMapping;
use App\Services\Integrations\Aggregator\BasePayload\Failure;
use App\Models\IntegrationCollectionMappings\NetsuiteCollectionMapping;
use App\Services\Integrations\Aggregator\CreditNotes\Payloads\Netsuite;

/**
 * Port of Rails' spec/services/integrations/aggregator/credit_notes/
 * payloads/netsuite_spec.rb — the creditmemo shape, the serialized
 * fullCreditNotePayload embed, the Ava-ra tax details and the add-on
 * mapping resolution pinned end-to-end.
 */
it('builds the netsuite credit memo body with the billable metric mapping end-to-end', function (): void {
    [$organization, $customer, $invoice, $integration, $integrationCustomer] = accountingCollectorPayloadFixture(NetsuiteIntegration::class);
    [$metric, $creditNote, $creditNoteItem] = accountingChargeCreditNote($organization, $customer, $invoice);

    NetsuiteMapping::factory()->forIntegration($integration)->forMappable('BillableMetric', $metric)->create([
        'organization_id' => $integration->organization_id,
    ]);

    $body = (new Netsuite($integrationCustomer, $creditNote))->body();

    expect($body['type'])->toBe('creditmemo')
        ->and($body['isDynamic'])->toBeTrue()
        ->and($body['columns']['tranid'])->toBe($creditNote->number)
        ->and($body['columns']['otherrefnum'])->toBe($creditNote->number)
        ->and($body['columns']['entity'])->toBe($integrationCustomer->external_customer_id)
        ->and($body['columns']['custbody_lago_id'])->toBe($creditNote->id)
        ->and($body['columns']['tranId'])->toBe($creditNote->id)
        ->and($body['columns']['taxregoverride'])->toBeTrue()
        ->and($body['columns']['taxdetailsoverride'])->toBeTrue()
        ->and($body['columns']['custbody_ava_disable_tax_calculation'])->toBeTrue()
        ->and($body['columns'])->not->toHaveKey('nexus');

    $line = $body['lines'][0]['lineItems'][0];

    expect($line['item'])->toBe('netsuite-123')
        ->and($line['account'])->toBe('netsuite-code-1')
        ->and($line['quantity'])->toBe(1)
        ->and($line['rate'])->toBe('2.12')
        ->and($line['taxdetailsreference'])->toBe($creditNoteItem->id)
        ->and($line['description'])->toBe('API');

    // The serialized credit note embed.
    $embedded = $body['options']['fullCreditNotePayload']['credit_note_payload'];

    expect($embedded['lago_id'])->toBe($creditNote->id)
        ->and($embedded['number'])->toBe($creditNote->number)
        ->and($embedded['lago_invoice_id'])->toBe($invoice->id)
        ->and($embedded['customer']['lago_id'])->toBe($customer->id)
        ->and($embedded['items'])->toHaveCount(1);

    // No tax mapping → no tax details section.
    expect($body)->not->toHaveKey('taxdetails');
});

it('includes the fixed_charge fee using the add_on mapping', function (): void {
    [$organization, $customer, $invoice, $integration, $integrationCustomer] = accountingCollectorPayloadFixture(NetsuiteIntegration::class);

    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $addOn = AddOn::factory()->for($organization, 'organization')->create();
    $fixedCharge = FixedCharge::factory()->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'add_on_id' => $addOn->id,
    ]);

    $fee = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'subscription_id' => null,
        'organization_id' => $organization->id,
        'billing_entity_id' => $customer->billing_entity_id,
        'fee_type' => FeeType::FixedCharge,
        'fixed_charge_id' => $fixedCharge->id,
        'amount_cents' => 2500,
        'precise_amount_cents' => '2500',
        'amount_currency' => 'EUR',
        'units' => '1',
        'taxes_amount_cents' => 0,
        'taxes_precise_amount_cents' => '0',
        'taxes_rate' => 0,
    ]);

    $creditNote = App\Models\CreditNote::factory()->forInvoice($invoice)->finalized()->create();
    $creditNoteItem = App\Models\CreditNoteItem::factory()->create([
        'credit_note_id' => $creditNote->id,
        'fee_id' => $fee->id,
        'organization_id' => $organization->id,
        'amount_cents' => 2500,
        'precise_amount_cents' => '2500',
        'amount_currency' => 'EUR',
    ]);
    $creditNote->refresh();

    NetsuiteMapping::factory()->forIntegration($integration)->forMappable('AddOn', $addOn)->create([
        'organization_id' => $integration->organization_id,
    ]);

    $lineItems = (new Netsuite($integrationCustomer, $creditNote))->body()['lines'][0]['lineItems'];

    expect($lineItems)->toHaveCount(1)
        ->and($lineItems[0]['item'])->toBe('netsuite-123')
        ->and($lineItems[0]['account'])->toBe('netsuite-code-1')
        ->and($lineItems[0]['taxdetailsreference'])->toBe($creditNoteItem->id);
});

it('emits the tax details when the tax mapping is complete', function (): void {
    [$organization, $customer, $invoice, $integration, $integrationCustomer] = accountingCollectorPayloadFixture(NetsuiteIntegration::class);
    [$metric, $creditNote] = accountingChargeCreditNote($organization, $customer, $invoice, 212, [
        'taxes_rate' => 20,
        'taxes_amount_cents' => 42,
    ]);

    // The tax line's rate is the FEE's taxes rate (Rails: fee.taxes_rate).
    $creditNote->items->first()->fee->update(['taxes_rate' => 20]);

    NetsuiteMapping::factory()->forIntegration($integration)->forMappable('BillableMetric', $metric)->create([
        'organization_id' => $integration->organization_id,
    ]);
    NetsuiteCollectionMapping::factory()->withMappingType('tax')->forIntegration($integration)->create([
        'organization_id' => $organization->id,
    ]);

    $body = (new Netsuite($integrationCustomer, $creditNote))->body();

    expect($body['columns']['nexus'])->toBe('tax-nexus-1');

    $taxLine = $body['taxdetails'][0]['lineItems'][0];

    expect($taxLine['taxdetailsreference'])->toBe($creditNote->items->first()->id)
        ->and($taxLine['taxbasis'])->toBe(1)
        // Rails divides by the subunit twice here (taxes_amount returns major
        // units, then amount() divides again) — 42.4 pins as 0.42.
        ->and($taxLine['taxamount'])->toBe('0.42')
        ->and((float) $taxLine['taxrate'])->toEqual(20.0)
        ->and($taxLine['taxtype'])->toBe('tax-type-1')
        ->and($taxLine['taxcode'])->toBe('tax-code-1');
});

it('emits the coupon line and coupon tax with the coupon mapping', function (): void {
    [$organization, $customer, $invoice, $integration, $integrationCustomer] = accountingCollectorPayloadFixture(NetsuiteIntegration::class);
    [$metric, $creditNote] = accountingChargeCreditNote($organization, $customer, $invoice);

    $creditNote->coupons_adjustment_amount_cents = 2000;
    $creditNote->save();

    NetsuiteMapping::factory()->forIntegration($integration)->forMappable('BillableMetric', $metric)->create([
        'organization_id' => $integration->organization_id,
    ]);
    NetsuiteCollectionMapping::factory()->withMappingType('coupon')->forIntegration($integration)->create([
        'organization_id' => $organization->id,
    ]);
    NetsuiteCollectionMapping::factory()->withMappingType('tax')->forIntegration($integration)->create([
        'organization_id' => $organization->id,
    ]);

    $body = (new Netsuite($integrationCustomer, $creditNote))->body();

    $couponLine = $body['lines'][0]['lineItems'][1];

    expect($couponLine['item'])->toBe('netsuite-123')
        ->and($couponLine['account'])->toBe('netsuite-code-1')
        ->and($couponLine['quantity'])->toBe(1)
        ->and($couponLine['rate'])->toBe('-20')
        ->and($couponLine['taxdetailsreference'])->toBe('coupon_item');

    $couponTax = $body['taxdetails'][0]['lineItems'][1];

    expect($couponTax['taxdetailsreference'])->toBe('coupon_item')
        ->and($couponTax['taxamount'])->toBe(0)
        ->and($couponTax['taxbasis'])->toBe(1)
        ->and($couponTax['taxrate'])->toBe($creditNote->taxes_rate);
});

it('fails with invalid_mapping when the fee has no mapping', function (): void {
    [$organization, $customer, $invoice, $integration, $integrationCustomer] = accountingCollectorPayloadFixture(NetsuiteIntegration::class);
    [, $creditNote] = accountingChargeCreditNote($organization, $customer, $invoice);

    new Netsuite($integrationCustomer, $creditNote)->body();
})->throws(Failure::class, 'invalid_mapping');
