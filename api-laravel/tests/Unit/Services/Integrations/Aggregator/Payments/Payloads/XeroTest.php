<?php

declare(strict_types=1);

require_once __DIR__.'/../../AccountingCollectorsFixture.php';

use App\Models\Payment;
use App\Models\Integrations\XeroIntegration;
use App\Services\Integrations\Aggregator\BasePayload\Failure;
use App\Services\Integrations\Aggregator\Payments\Payloads\Xero;
use App\Models\IntegrationCollectionMappings\XeroCollectionMapping;
use App\Services\Integrations\Aggregator\Payments\Payloads\Factory;

/**
 * Port of Rails' spec/services/integrations/aggregator/payments/
 * payloads/xero_spec.rb — the shared payment shape with the account
 * collection mapping pinned end-to-end.
 */
it('builds the xero payment body with the account mapping end-to-end', function (): void {
    [$organization, $customer, $invoice, $integration] = accountingCollectorPayloadFixture(XeroIntegration::class);

    $integrationInvoice = App\Models\IntegrationResource::query()->create([
        'organization_id' => $organization->id,
        'integration_id' => $integration->id,
        'external_id' => 'xero-inv-1',
        'syncable_id' => $invoice->id,
        'syncable_type' => 'Invoice',
        'resource_type' => App\Models\IntegrationResource::RESOURCE_TYPE_INVOICE,
    ]);

    $payment = Payment::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'payable_type' => 'Invoice',
        'payable_id' => $invoice->id,
        'amount_cents' => 150,
    ]);

    XeroCollectionMapping::factory()->withMappingType('account')->forIntegration($integration)->create([
        'organization_id' => $organization->id,
    ]);

    $body = (new Xero($integration, $payment))->body();

    expect($body)->toHaveCount(1)
        ->and($body[0]['invoice_id'])->toBe('xero-inv-1')
        ->and($body[0]['account_code'])->toBe('xero-code-1')
        ->and($body[0]['date'])->toBe(\Illuminate\Support\Facades\Date::parse($payment->created_at)->toAtomString())
        ->and($body[0]['amount_cents'])->toBe(150);

    // The factory dispatches the Xero payload for a Xero integration.
    expect(Factory::new_instance($integration, $payment))->toBeInstanceOf(Xero::class);
});

it('fails with invoice_missing when the invoice never synced', function (): void {
    [$organization, $customer, $invoice, $integration] = accountingCollectorPayloadFixture(XeroIntegration::class);

    $payment = Payment::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'payable_type' => 'Invoice',
        'payable_id' => $invoice->id,
    ]);

    new Xero($integration, $payment)->body();
})->throws(Failure::class, 'invoice_missing');
