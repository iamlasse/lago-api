<?php

declare(strict_types=1);

require_once __DIR__.'/../../AccountingCollectorsFixture.php';

use App\Models\Payment;
use App\Models\Integrations\NetsuiteIntegration;
use App\Services\Integrations\Aggregator\Payments\Payloads\Netsuite;

/**
 * Port of Rails' spec/services/integrations/aggregator/payments/
 * payloads/netsuite_spec.rb — the customerpayment restlet shape.
 */
it('builds the netsuite customerpayment body', function (): void {
    [$organization, $customer, $invoice, $integration, $integrationCustomer] = accountingCollectorPayloadFixture(NetsuiteIntegration::class);

    $integrationInvoice = App\Models\IntegrationResource::query()->create([
        'organization_id' => $organization->id,
        'integration_id' => $integration->id,
        'external_id' => 'ns-inv-1',
        'syncable_id' => $invoice->id,
        'syncable_type' => 'Invoice',
        'resource_type' => App\Models\IntegrationResource::RESOURCE_TYPE_INVOICE,
    ]);

    $payment = Payment::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'payable_type' => 'Invoice',
        'payable_id' => $invoice->id,
        'amount_cents' => 100,
    ]);

    $body = (new Netsuite($integration, $payment))->body();

    expect($body['isDynamic'])->toBeTrue()
        ->and($body['type'])->toBe('customerpayment')
        ->and($body['columns']['customer'])->toBe($integrationCustomer->external_customer_id)
        ->and($body['columns']['payment'])->toBe('1')
        ->and($body['options']['ignoreMandatoryFields'])->toBeFalse()
        ->and($body['lines'][0]['sublistId'])->toBe('apply')
        ->and($body['lines'][0]['lineItems'])->toHaveCount(1)
        ->and($body['lines'][0]['lineItems'][0]['amount'])->toBe('1')
        ->and($body['lines'][0]['lineItems'][0]['apply'])->toBeTrue()
        ->and($body['lines'][0]['lineItems'][0]['doc'])->toBe('ns-inv-1');
});
