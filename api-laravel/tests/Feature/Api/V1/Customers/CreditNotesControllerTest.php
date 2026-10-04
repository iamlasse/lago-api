<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Customer;
use App\Models\CreditNote;
use App\Models\Organization;
use Illuminate\Testing\Fluent\AssertableJson;

uses()->group(
    'ledger:rest:GET:/api/v1/customers/:external_id/credit_notes',
    'ledger:rest:GET:/api/v2/customers/:external_id/credit_notes',
);

/**
 * Port of Rails' spec/requests/api/v1/customers/credit_notes_controller_spec.rb
 * — the CreditNoteIndex concern scoped to one customer.
 */
function customerCreditNotesSetup(array $customerAttributes = []): array
{
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->forOrganization($organization)->create($customerAttributes);
    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);

    return [$organization, $organization->apiKeys()->first(), $customer, $invoice];
}

it('lists the customer credit notes', function (): void {
    [$organization, $apiKey, $customer, $invoice] = customerCreditNotesSetup(['external_id' => 'cust-cn-1']);

    $creditNote = CreditNote::factory()->forInvoice($invoice)->create();

    $otherCustomer = Customer::factory()->forOrganization($organization)->create();
    $otherInvoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $otherCustomer->id,
    ]);
    CreditNote::factory()->forInvoice($otherInvoice)->create();

    $this->getJson('/api/v1/customers/cust-cn-1/credit_notes', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (AssertableJson $json) use ($creditNote): void {
            $json->count('credit_notes', 1)
                ->where('credit_notes.0.lago_id', $creditNote->id)
                ->where('meta.total_count', 1)
                ->etc();
        });
});

it('answers not_found for an unknown customer', function (): void {
    $organization = Organization::factory()->create();

    $this->getJson('/api/v2/customers/unknown/credit_notes', ['Authorization' => 'Bearer '.$organization->apiKeys()->first()->value])
        ->assertNotFound()
        ->assertJsonPath('code', 'customer_not_found');
});
