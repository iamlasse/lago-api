<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Models\Tax;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\CreditNote;
use App\Models\Organization;
use App\Models\FeeAppliedTax;
use App\Models\InvoiceAppliedTax;
use App\Enums\InvoicePaymentStatus;
use App\Enums\CreditNoteCreditStatus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;

uses()->group(
    'ledger:rest:POST:/api/v1/credit_notes',
    'ledger:rest:GET:/api/v1/credit_notes',
    'ledger:rest:GET:/api/v1/credit_notes/:id',
    'ledger:rest:PUT:/api/v1/credit_notes/:id',
    'ledger:rest:PATCH:/api/v1/credit_notes/:id',
    'ledger:rest:PUT:/api/v1/credit_notes/:id/void',
    'ledger:rest:POST:/api/v1/credit_notes/estimate',
    'ledger:rest:POST:/api/v1/credit_notes/:id/download',
    'ledger:rest:POST:/api/v1/credit_notes/:id/download_pdf',
    'ledger:rest:POST:/api/v1/credit_notes/:id/download_xml',
    'ledger:rest:POST:/api/v2/credit_notes',
    'ledger:rest:GET:/api/v2/credit_notes',
    'ledger:rest:GET:/api/v2/credit_notes/:id',
    'ledger:rest:PATCH:/api/v2/credit_notes/:id',
    'ledger:rest:PUT:/api/v2/credit_notes/:id/void',
    'ledger:rest:POST:/api/v2/credit_notes/estimate',
    'ledger:rest:POST:/api/v2/credit_notes/:id/download_pdf',
    'ledger:rest:POST:/api/v2/credit_notes/:id/download_xml',
);

/**
 * Port of Rails' spec/requests/api/v1/credit_notes_controller_spec.rb
 * (create / index / show / update / void / estimate / downloads).
 *
 * Not ported: the metadata subresource (Metadata::ItemMetadata is not
 * attached to credit notes) and resend_email (Emails::ResendService) —
 * the routes are not registered (see routes/api.php).
 */
function creditNotesEndpointOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create($attributes);

    return [$organization, $organization->apiKeys()->first()];
}

function creditNotesInvoice(array $overrides = []): Invoice
{
    return Invoice::factory()->create(array_merge([
        'currency' => 'EUR',
        'fees_amount_cents' => 20,
        'total_amount_cents' => 24,
        'total_paid_amount_cents' => 24,
        'payment_status' => InvoicePaymentStatus::Succeeded,
        'taxes_rate' => 20,
        'version_number' => 2,
    ], $overrides));
}

function creditNotesTax(Invoice $invoice, array $fees, float $rate = 20.0): Tax
{
    $tax = Tax::factory()->create([
        'organization_id' => $invoice->organization_id,
        'rate' => $rate,
    ]);

    foreach ($fees as $fee) {
        FeeAppliedTax::factory()->create([
            'fee_id' => $fee->id,
            'tax_id' => $tax->id,
            'organization_id' => $invoice->organization_id,
        ]);
    }

    InvoiceAppliedTax::factory()->create([
        'invoice_id' => $invoice->id,
        'tax_id' => $tax->id,
        'organization_id' => $invoice->organization_id,
    ]);

    return $tax;
}

beforeEach(function (): void {
    Queue::fake();
    config(['lago.license' => 'premium']);
});

afterEach(function (): void {
    config(['lago.license' => null]);
});

// -- POST /api/v1/credit_notes ----------------------------------------------------

it('creates a credit note from an invoice', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $invoice = creditNotesInvoice(['organization_id' => $organization->id, 'customer_id' => $customer->id]);

    $fee1 = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $organization->id,
        'amount_cents' => 10,
        'taxes_amount_cents' => 1,
        'taxes_rate' => 20,
    ]);
    creditNotesTax($invoice, [$fee1]);

    $this->postJson('/api/v1/credit_notes', ['credit_note' => [
        'invoice_id' => $invoice->id,
        'reason' => 'duplicated_charge',
        'description' => 'Charged twice',
        // items 10 + taxes 2 — the total must match the item amounts.
        'credit_amount_cents' => 12,
        'items' => [
            ['fee_id' => $fee1->id, 'amount_cents' => 10],
        ],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (AssertableJson $json) use ($invoice, $customer): void {
            $json->where('credit_note.lago_invoice_id', $invoice->id)
                ->where('credit_note.invoice_number', $invoice->number)
                ->where('credit_note.reason', 'duplicated_charge')
                ->where('credit_note.description', 'Charged twice')
                ->where('credit_note.credit_status', 'available')
                ->where('credit_note.refund_status', null)
                ->where('credit_note.credit_amount_cents', 12)
                ->where('credit_note.currency', 'EUR')
                ->where('credit_note.customer.lago_id', $customer->id)
                ->has('credit_note.items', 1)
                ->has('credit_note.applied_taxes', 1)
                ->etc();
        });
});

it('answers not_found when the invoice is unknown', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();

    $this->postJson('/api/v1/credit_notes', ['credit_note' => [
        'invoice_id' => '00000000-0000-0000-0000-000000000000',
        'reason' => 'other',
        'credit_amount_cents' => 10,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound()
        ->assertJsonPath('code', 'invoice_not_found');
});

it('answers forbidden without a premium license', function (): void {
    config(['lago.license' => null]);

    [$organization, $apiKey] = creditNotesEndpointOrganization();
    $invoice = creditNotesInvoice(['organization_id' => $organization->id]);

    $this->postJson('/api/v1/credit_notes', ['credit_note' => [
        'invoice_id' => $invoice->id,
        'reason' => 'other',
        'credit_amount_cents' => 10,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertForbidden()
        ->assertJsonPath('code', 'feature_unavailable');
});

it('validates the reason', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();
    $invoice = creditNotesInvoice(['organization_id' => $organization->id]);

    $this->postJson('/api/v1/credit_notes', ['credit_note' => [
        'invoice_id' => $invoice->id,
        'reason' => 'not_a_reason',
        'credit_amount_cents' => 10,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJsonPath('error_details.reason.0', 'invalid_value');
});

// -- GET /api/v1/credit_notes -----------------------------------------------------

it('lists finalized credit notes', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $invoice = creditNotesInvoice(['organization_id' => $organization->id, 'customer_id' => $customer->id]);

    $creditNote = CreditNote::factory()->forInvoice($invoice)->create();
    CreditNote::factory()->forInvoice($invoice)->draft()->create();

    $this->getJson('/api/v1/credit_notes', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (AssertableJson $json) use ($creditNote): void {
            $json->count('credit_notes', 1)
                ->where('credit_notes.0.lago_id', $creditNote->id)
                ->where('credit_notes.0.credit_status', 'available')
                ->where('meta.total_count', 1)
                ->etc();
        });
});

it('filters credit notes by external_customer_id, currency and credit_status', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create(['external_id' => 'cust-123']);
    $otherCustomer = Customer::factory()->forOrganization($organization)->create();

    $invoice = creditNotesInvoice(['organization_id' => $organization->id, 'customer_id' => $customer->id]);
    $otherInvoice = creditNotesInvoice(['organization_id' => $organization->id, 'customer_id' => $otherCustomer->id]);

    $creditNote = CreditNote::factory()->forInvoice($invoice)->create();
    CreditNote::factory()->forInvoice($otherInvoice)->create();

    $this->getJson('/api/v1/credit_notes?external_customer_id=cust-123', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json
            ->count('credit_notes', 1)
            ->where('credit_notes.0.lago_id', $creditNote->id)
            ->etc());

    $this->getJson('/api/v1/credit_notes?currency=USD', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json->count('credit_notes', 0)->etc());

    $this->getJson('/api/v1/credit_notes?credit_status[]=available', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json->count('credit_notes', 2)->etc());
});

it('filters credit notes by types', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();
    $invoice = creditNotesInvoice(['organization_id' => $organization->id]);

    CreditNote::factory()->forInvoice($invoice)->create(['refund_amount_cents' => 0]);
    CreditNote::factory()->forInvoice($invoice)->create([
        'credit_amount_cents' => 0,
        'balance_amount_cents' => 0,
        'refund_amount_cents' => 50,
    ]);

    $this->getJson('/api/v1/credit_notes?types[]=refund', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json->count('credit_notes', 1)
            ->where('credit_notes.0.refund_amount_cents', 50)
            ->etc());
});

it('filters credit notes by search_term on the number', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();
    $invoice = creditNotesInvoice(['organization_id' => $organization->id]);

    $creditNote = CreditNote::factory()->forInvoice($invoice)->create();

    $this->getJson('/api/v1/credit_notes?search_term='.$creditNote->number, ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json->count('credit_notes', 1)
            ->where('credit_notes.0.lago_id', $creditNote->id)
            ->etc());
});

it('filters credit notes by invoice_number', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();
    $invoice = creditNotesInvoice(['organization_id' => $organization->id, 'number' => 'INV-1234']);

    $creditNote = CreditNote::factory()->forInvoice($invoice)->create();
    $otherInvoice = creditNotesInvoice(['organization_id' => $organization->id, 'number' => 'INV-5678']);
    CreditNote::factory()->forInvoice($otherInvoice)->create();

    $this->getJson('/api/v1/credit_notes?invoice_number=INV-1234', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json->count('credit_notes', 1)
            ->where('credit_notes.0.lago_id', $creditNote->id)
            ->etc());
});

it('answers not_found when the billing_entity_codes filter is unknown', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();

    $this->getJson('/api/v1/credit_notes?billing_entity_codes[]=unknown', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound()
        ->assertJsonPath('code', 'billing_entity_not_found');
});

// -- GET /api/v1/credit_notes/:id -------------------------------------------------

it('shows a finalized credit note', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();
    $invoice = creditNotesInvoice(['organization_id' => $organization->id]);
    $creditNote = CreditNote::factory()->forInvoice($invoice)->create();

    $this->getJson('/api/v1/credit_notes/'.$creditNote->id, ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('credit_note.lago_id', $creditNote->id)
        ->assertJsonPath('credit_note.invoice_number', $invoice->number);
});

it('answers not_found for a draft credit note on show', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();
    $invoice = creditNotesInvoice(['organization_id' => $organization->id]);
    $creditNote = CreditNote::factory()->forInvoice($invoice)->draft()->create();

    $this->getJson('/api/v1/credit_notes/'.$creditNote->id, ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound()
        ->assertJsonPath('code', 'credit_note_not_found');
});

it('answers not_found for an unknown credit note on show', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();

    $this->getJson('/api/v1/credit_notes/00000000-0000-0000-0000-000000000000', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- PUT/PATCH /api/v1/credit_notes/:id ---------------------------------------------

it('updates the refund status of a credit note', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();
    $invoice = creditNotesInvoice(['organization_id' => $organization->id]);
    $creditNote = CreditNote::factory()->forInvoice($invoice)->create([
        'refund_amount_cents' => 50,
        'refund_status' => App\Enums\CreditNoteRefundStatus::Pending,
    ]);

    $this->putJson('/api/v1/credit_notes/'.$creditNote->id, ['credit_note' => [
        'refund_status' => 'succeeded',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('credit_note.lago_id', $creditNote->id)
        ->assertJsonPath('credit_note.refund_status', 'succeeded');

    expect($creditNote->refresh()->refunded_at)->not->toBeNull();
});

it('updates a credit note over PATCH too', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();
    $invoice = creditNotesInvoice(['organization_id' => $organization->id]);
    $creditNote = CreditNote::factory()->forInvoice($invoice)->create([
        'refund_amount_cents' => 50,
        'refund_status' => App\Enums\CreditNoteRefundStatus::Pending,
    ]);

    $this->patchJson('/api/v2/credit_notes/'.$creditNote->id, ['credit_note' => [
        'refund_status' => 'failed',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('credit_note.refund_status', 'failed');
});

it('rejects an invalid refund status', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();
    $invoice = creditNotesInvoice(['organization_id' => $organization->id]);
    $creditNote = CreditNote::factory()->forInvoice($invoice)->create();

    $this->putJson('/api/v1/credit_notes/'.$creditNote->id, ['credit_note' => [
        'refund_status' => 'nope',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_errors');
});

it('answers not_found when updating a draft credit note', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();
    $invoice = creditNotesInvoice(['organization_id' => $organization->id]);
    $creditNote = CreditNote::factory()->forInvoice($invoice)->draft()->create();

    $this->putJson('/api/v1/credit_notes/'.$creditNote->id, ['credit_note' => [
        'refund_status' => 'succeeded',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound()
        ->assertJsonPath('code', 'credit_note_not_found');
});

// -- PUT /api/v1/credit_notes/:id/void ----------------------------------------------

it('voids a credit note', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();
    $invoice = creditNotesInvoice(['organization_id' => $organization->id]);
    $creditNote = CreditNote::factory()->forInvoice($invoice)->create();

    $this->putJson('/api/v1/credit_notes/'.$creditNote->id.'/void', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('credit_note.lago_id', $creditNote->id)
        ->assertJsonPath('credit_note.credit_status', 'voided');

    expect($creditNote->refresh()->creditStatusEnum())->toBe(CreditNoteCreditStatus::Voided);
});

it('answers not_allowed when the credit note has no voidable amount', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();
    $invoice = creditNotesInvoice(['organization_id' => $organization->id]);
    $creditNote = CreditNote::factory()->forInvoice($invoice)->create();

    $creditNote->markAsVoided();

    $this->putJson('/api/v2/credit_notes/'.$creditNote->id.'/void', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertStatus(405)
        ->assertJsonPath('code', 'no_voidable_amount');
});

it('answers not_found when voiding an unknown credit note', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();

    $this->putJson('/api/v1/credit_notes/00000000-0000-0000-0000-000000000000/void', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- POST /api/v1/credit_notes/estimate ----------------------------------------------

it('estimates a credit note', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();
    $invoice = creditNotesInvoice(['organization_id' => $organization->id]);

    $fee = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $organization->id,
        'amount_cents' => 10,
        'taxes_amount_cents' => 2,
        'taxes_rate' => 20,
    ]);
    creditNotesTax($invoice, [$fee]);

    $this->postJson('/api/v1/credit_notes/estimate', ['credit_note' => [
        'invoice_id' => $invoice->id,
        'items' => [
            ['fee_id' => $fee->id, 'amount_cents' => 10],
        ],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (AssertableJson $json) use ($invoice, $fee): void {
            $json->where('estimated_credit_note.lago_invoice_id', $invoice->id)
                ->where('estimated_credit_note.invoice_number', $invoice->number)
                ->where('estimated_credit_note.currency', 'EUR')
                ->where('estimated_credit_note.taxes_amount_cents', 2)
                ->where('estimated_credit_note.max_creditable_amount_cents', 12)
                ->where('estimated_credit_note.items.0.lago_fee_id', $fee->id)
                ->has('estimated_credit_note.applied_taxes', 1)
                ->etc();
        });
});

it('answers not_found when the estimated invoice is unknown', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();

    $this->postJson('/api/v1/credit_notes/estimate', ['credit_note' => [
        'invoice_id' => '00000000-0000-0000-0000-000000000000',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- POST /api/v1/credit_notes/:id/download[_pdf|_xml] ---------------------------------

it('answers not_found when downloading an unknown credit note', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();

    $this->postJson('/api/v1/credit_notes/00000000-0000-0000-0000-000000000000/download_pdf', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

it('serializes the credit note when the file is present', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();
    $invoice = creditNotesInvoice(['organization_id' => $organization->id]);
    $creditNote = CreditNote::factory()->forInvoice($invoice)->create(['file' => 'file-url']);

    $this->postJson('/api/v1/credit_notes/'.$creditNote->id.'/download_pdf', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('credit_note.lago_id', $creditNote->id);

    $this->postJson('/api/v2/credit_notes/'.$creditNote->id.'/download', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('credit_note.lago_id', $creditNote->id);
});

it('answers ok without a file to generate', function (): void {
    [$organization, $apiKey] = creditNotesEndpointOrganization();
    $invoice = creditNotesInvoice(['organization_id' => $organization->id]);
    $creditNote = CreditNote::factory()->forInvoice($invoice)->create();

    $this->postJson('/api/v1/credit_notes/'.$creditNote->id.'/download_pdf', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk();

    $this->postJson('/api/v1/credit_notes/'.$creditNote->id.'/download_xml', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk();
});
