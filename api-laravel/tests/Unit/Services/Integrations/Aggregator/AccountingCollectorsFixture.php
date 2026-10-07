<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\BillableMetric;
use App\Models\Integrations\XeroIntegration;
use App\Models\IntegrationCustomers\XeroCustomer;
use App\Models\IntegrationCustomers\NetsuiteCustomer;

/*
 * Shared fixtures for the credit-notes + payments accounting collectors
 * (the payload-shape tests pinning the item_code/account mapping resolution
 * end-to-end). Guarded like tests/Unit/Services/Charges/Validators/
 * ValidatorTestCase.php so sibling test files can require them.
 */

if (! function_exists('accountingCollectorPayloadFixture')) {
    /**
     * An organization / customer / invoice / accounting integration +
     * integration customer quadruple (Xero or Netsuite by class).
     */
    function accountingCollectorPayloadFixture(string $integrationClass): array
    {
        $organization = Organization::factory()->create();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $invoice = Invoice::factory()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'currency' => 'EUR',
        ]);
        $integration = $integrationClass::factory()->forOrganization($organization)->create();

        $integrationCustomerClass = $integration instanceof XeroIntegration ? XeroCustomer::class : NetsuiteCustomer::class;
        $integrationCustomer = $integrationCustomerClass::factory()
            ->forIntegration($integration)
            ->forCustomer($customer)
            ->create();

        return [$organization, $customer, $invoice, $integration, $integrationCustomer];
    }
}

if (! function_exists('accountingChargeFee')) {
    /** A charge fee on the invoice over an "API" billable metric. */
    function accountingChargeFee(Organization $organization, Customer $customer, Invoice $invoice, int $amountCents = 212): Fee
    {
        $plan = App\Models\Plan::factory()->create(['organization_id' => $organization->id]);
        $metric = BillableMetric::factory()->create([
            'organization_id' => $organization->id,
            'code' => 'api',
            'name' => 'API',
        ]);
        $charge = Charge::factory()->create([
            'plan_id' => $plan->id,
            'organization_id' => $organization->id,
            'billable_metric_id' => $metric->id,
            'charge_model' => 'standard',
            'properties' => ['amount' => '1'],
        ]);
        $subscription = App\Models\Subscription::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'organization_id' => $organization->id,
        ]);

        return Fee::factory()->chargeFee()->create([
            'invoice_id' => $invoice->id,
            'subscription_id' => $subscription->id,
            'organization_id' => $organization->id,
            'billing_entity_id' => $customer->billing_entity_id,
            'charge_id' => $charge->id,
            'invoiceable_type' => 'Charge',
            'invoiceable_id' => $charge->id,
            'amount_cents' => $amountCents,
            'precise_amount_cents' => (string) $amountCents,
            'amount_currency' => 'EUR',
            'unit_amount_cents' => $amountCents,
            'precise_unit_amount' => '1',
            'units' => '1',
            'taxes_amount_cents' => 0,
            'taxes_precise_amount_cents' => '0',
            'taxes_rate' => 0,
        ]);
    }
}

if (! function_exists('accountingChargeCreditNote')) {
    /**
     * A finalized credit note on the invoice with one charge-fee item of
     * `amountCents`. Returns [metric, creditNote, creditNoteItem].
     */
    function accountingChargeCreditNote(Organization $organization, Customer $customer, Invoice $invoice, int $amountCents = 212, array $creditNoteAttributes = []): array
    {
        $fee = accountingChargeFee($organization, $customer, $invoice, $amountCents);

        $creditNote = App\Models\CreditNote::factory()
            ->forInvoice($invoice)
            ->finalized()
            ->create($creditNoteAttributes);

        $creditNoteItem = App\Models\CreditNoteItem::factory()->create([
            'credit_note_id' => $creditNote->id,
            'fee_id' => $fee->id,
            'organization_id' => $organization->id,
            'amount_cents' => $amountCents,
            'precise_amount_cents' => (string) $amountCents,
            'amount_currency' => 'EUR',
        ]);

        $creditNote->refresh();

        return [$fee->charge->billableMetric, $creditNote, $creditNoteItem];
    }
}
