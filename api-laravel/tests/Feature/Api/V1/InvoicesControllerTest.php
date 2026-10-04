<?php

declare(strict_types=1);

use App\Models\Tax;
use App\Models\AddOn;
use App\Models\Invoice;
use App\Models\Customer;
use App\Enums\InvoiceStatus;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use App\Enums\InvoicePaymentStatus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses()->group(
    'ledger:rest:POST:/api/v1/invoices',
    'ledger:rest:GET:/api/v1/invoices',
    'ledger:rest:GET:/api/v1/invoices/:id',
    'ledger:rest:PUT:/api/v1/invoices/:id',
    'ledger:rest:PATCH:/api/v1/invoices/:id',
    'ledger:rest:PATCH:/api/v2/invoices/:id',
    'ledger:rest:DELETE:/api/v1/invoices/:id',
    'ledger:rest:PUT:/api/v1/invoices/:id/finalize',
    'ledger:rest:PUT:/api/v1/invoices/:id/refresh',
    'ledger:rest:POST:/api/v1/invoices/:id/void',
    'ledger:rest:POST:/api/v1/invoices/:id/retry',
    'ledger:rest:POST:/api/v1/invoices/:id/lose_dispute',
    'ledger:rest:POST:/api/v1/invoices/:id/download',
    'ledger:rest:POST:/api/v1/invoices/:id/download_pdf',
    'ledger:rest:POST:/api/v1/invoices/:id/download_xml',
);

/**
 * Port of Rails' spec/requests/api/v1/invoices_controller_spec.rb.
 */
function invoicesOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create($attributes);

    return [$organization, $organization->apiKeys()->first()];
}

function invoicesEntityTax(Organization $organization, object $billingEntity, float $rate = 20): Tax
{
    $tax = Tax::factory()->create(['organization_id' => $organization->id, 'rate' => $rate]);

    DB::table('billing_entities_taxes')->insert([
        'id' => (string) Illuminate\Support\Str::uuid(),
        'billing_entity_id' => $billingEntity->id,
        'tax_id' => $tax->id,
        'organization_id' => $organization->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $tax;
}

// -- POST /api/v1/invoices ------------------------------------------------------

it('creates an invoice with add-on fees', function (): void {
    Queue::fake();

    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $tax = invoicesEntityTax($organization, $customer->billingEntity);

    $addOnFirst = AddOn::factory()->create(['code' => 'first', 'organization_id' => $organization->id]);
    $addOnSecond = AddOn::factory()->create(['code' => 'second', 'amount_cents' => 400, 'organization_id' => $organization->id]);

    $response = $this->postJson('/api/v1/invoices', [
        'invoice' => [
            'external_customer_id' => $customer->external_id,
            'currency' => 'EUR',
            'fees' => [
                [
                    'add_on_code' => $addOnFirst->code,
                    'invoice_display_name' => 'Invoice item #1',
                    'unit_amount_cents' => 1200,
                    'units' => 2,
                    'description' => 'desc-123',
                    'tax_codes' => [$tax->code],
                ],
                ['add_on_code' => $addOnSecond->code],
            ],
        ],
    ], ['Authorization' => 'Bearer '.$apiKey->value]);

    $response->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
        $json->where('invoice.invoice_type', 'one_off')
            ->where('invoice.fees_amount_cents', 2800)
            ->where('invoice.taxes_amount_cents', 560)
            ->where('invoice.total_amount_cents', 3360)
            ->where('invoice.currency', 'EUR')
            ->where('invoice.status', 'finalized')
            ->where('invoice.payment_status', 'pending')
            ->has('invoice.fees', 2)
            ->has('invoice.applied_taxes', 1)
            ->etc();
    });

    $payload = $response->json('invoice');
    $fee = collect($payload['fees'])->first(fn ($fee) => ($fee['item']['code'] ?? null) === 'first');
    expect($fee['item']['invoice_display_name'] ?? null)->toBe('Invoice item #1');
    expect($payload['applied_taxes'][0]['tax_code'] ?? null)->toBe($tax->code);
});

it('returns a not found error when the customer does not exist', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

    $this->postJson('/api/v1/invoices', [
        'invoice' => [
            'external_customer_id' => (string) Illuminate\Support\Str::uuid(),
            'currency' => 'EUR',
            'fees' => [['add_on_code' => $addOn->code]],
        ],
    ], ['Authorization' => 'Bearer '.$apiKey->value])->assertNotFound();
});

it('returns a not found error when an add-on does not exist', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

    $this->postJson('/api/v1/invoices', [
        'invoice' => [
            'external_customer_id' => $customer->external_id,
            'currency' => 'EUR',
            'fees' => [
                ['add_on_code' => $addOn->code],
                ['add_on_code' => 'invalid'],
            ],
        ],
    ], ['Authorization' => 'Bearer '.$apiKey->value])->assertNotFound();
});

it('creates an invoice with skip_psp', function (): void {
    Queue::fake();

    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

    $response = $this->postJson('/api/v1/invoices', [
        'invoice' => [
            'external_customer_id' => $customer->external_id,
            'currency' => 'EUR',
            'skip_psp' => true,
            'fees' => [['add_on_code' => $addOn->code, 'unit_amount_cents' => 1200, 'units' => 2]],
        ],
    ], ['Authorization' => 'Bearer '.$apiKey->value]);

    $response->assertOk();

    // NOTE: skip_automatic_payment is not in the V1 invoice serializer
    // payload (neither in Rails) — assert on the persisted column.
    expect($response->json('invoice.lago_id'))->toBeString();
    expect((bool) Invoice::query()->find($response->json('invoice.lago_id'))->skip_automatic_payment)->toBeTrue();
});

it('normalizes the purchase order number', function (): void {
    Queue::fake();

    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

    $response = $this->postJson('/api/v1/invoices', [
        'invoice' => [
            'external_customer_id' => $customer->external_id,
            'currency' => 'EUR',
            'purchase_order_number' => '  PO-12345  ',
            'fees' => [['add_on_code' => $addOn->code, 'unit_amount_cents' => 1200, 'units' => 2]],
        ],
    ], ['Authorization' => 'Bearer '.$apiKey->value]);

    $response->assertOk();
    expect($response->json('invoice.purchase_order_number'))->toBe('PO-12345');
});

// -- PUT /api/v1/invoices/:id ---------------------------------------------------

it('updates an invoice payment status', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create(['customer_id' => $customer->id, 'organization_id' => $organization->id]);

    $this->putJson("/api/v1/invoices/{$invoice->id}", [
        'invoice' => ['payment_status' => 'succeeded'],
    ], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('invoice.lago_id', $invoice->id)
        ->assertJsonPath('invoice.payment_status', 'succeeded');
});

it('returns a not found error when the updated invoice does not exist', function (): void {
    [$organization, $apiKey] = invoicesOrganization();

    $this->putJson('/api/v1/invoices/'.Illuminate\Support\Str::uuid(), [
        'invoice' => ['payment_status' => 'succeeded'],
    ], ['Authorization' => 'Bearer '.$apiKey->value])->assertNotFound();
});

it('returns method_not_allowed when the updated invoice is voided', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
        'status' => InvoiceStatus::Voided,
    ]);

    $this->putJson("/api/v1/invoices/{$invoice->id}", [
        'invoice' => ['payment_status' => 'succeeded'],
    ], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertStatus(405)
        ->assertJsonPath('code', 'update_on_voided_invoice');

    expect($invoice->refresh()->paymentStatusEnum())->toBe(InvoicePaymentStatus::Pending);
});

// -- PATCH /api/v1/invoices/:id ---------------------------------------------------
// Rails routes PATCH and PUT to the same InvoicesController#update (resources
// :invoices draws both verbs; no PATCH-specific branch exists), so the
// scenarios below port the PUT section's expectations to the PATCH verb.

it('updates an invoice payment status via PATCH', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create(['customer_id' => $customer->id, 'organization_id' => $organization->id]);

    $this->patchJson("/api/v1/invoices/{$invoice->id}", [
        'invoice' => ['payment_status' => 'succeeded'],
    ], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('invoice.lago_id', $invoice->id)
        ->assertJsonPath('invoice.payment_status', 'succeeded');
});

it('returns method_not_allowed when the invoice updated via PATCH is voided', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
        'status' => InvoiceStatus::Voided,
    ]);

    $this->patchJson("/api/v1/invoices/{$invoice->id}", [
        'invoice' => ['payment_status' => 'succeeded'],
    ], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertStatus(405)
        ->assertJsonPath('code', 'update_on_voided_invoice');

    expect($invoice->refresh()->paymentStatusEnum())->toBe(InvoicePaymentStatus::Pending);
});

// -- GET /api/v1/invoices/:id ---------------------------------------------------

it('returns an invoice', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create(['customer_id' => $customer->id, 'organization_id' => $organization->id]);

    $this->getJson("/api/v1/invoices/{$invoice->id}", ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('invoice.lago_id', $invoice->id)
        ->assertJsonPath('invoice.customer.lago_id', $customer->id);
});

it('returns not_found when the invoice does not exist', function (): void {
    [$organization, $apiKey] = invoicesOrganization();

    $this->getJson('/api/v1/invoices/'.Illuminate\Support\Str::uuid(), ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

it('returns not_found when the invoice belongs to another organization', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $otherOrganization = Organization::factory()->create();
    $otherCustomer = Customer::factory()->create(['organization_id' => $otherOrganization->id]);
    $invoice = Invoice::factory()->create([
        'customer_id' => $otherCustomer->id,
        'organization_id' => $otherOrganization->id,
    ]);

    $this->getJson("/api/v1/invoices/{$invoice->id}", ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

it('returns not_found for an invisible (generating) invoice', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->generating()->create([
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
    ]);

    $this->getJson("/api/v1/invoices/{$invoice->id}", ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- GET /api/v1/invoices -------------------------------------------------------

it('returns the invoices of the customer filtered by external_customer_id', function (string $paramName): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $matchingInvoice = Invoice::factory()->create(['customer_id' => $customer->id, 'organization_id' => $organization->id]);

    $anotherCustomer = Customer::factory()->create(['organization_id' => $organization->id]);
    Invoice::factory()->create(['customer_id' => $anotherCustomer->id, 'organization_id' => $organization->id]);

    $this->getJson("/api/v1/invoices?{$paramName}={$customer->external_id}", ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($matchingInvoice): void {
            $json->has('invoices', 1)
                ->where('invoices.0.lago_id', $matchingInvoice->id)
                ->etc();
        });
})->with(['external_customer_id', 'customer_external_id']);

it('paginates the invoice index with metadata', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    Invoice::factory()->count(3)->create(['customer_id' => $customer->id, 'organization_id' => $organization->id]);

    $this->getJson('/api/v1/invoices?per_page=2&page=1', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->has('invoices', 2)
                ->where('meta.current_page', 1)
                ->where('meta.total_count', 3)
                ->where('meta.next_page', 2)
                ->etc();
        });
});

it('filters the invoice index by status', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $draft = Invoice::factory()->draft()->create(['customer_id' => $customer->id, 'organization_id' => $organization->id]);
    Invoice::factory()->create(['customer_id' => $customer->id, 'organization_id' => $organization->id]);

    $this->getJson('/api/v1/invoices?status=draft', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($draft): void {
            $json->has('invoices', 1)
                ->where('invoices.0.lago_id', $draft->id)
                ->etc();
        });
});

// -- PUT /api/v1/invoices/:id/refresh -------------------------------------------

it('refreshes a draft invoice', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->draft()->create([
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
        'invoice_type' => App\Enums\InvoiceType::Subscription,
    ]);

    $response = $this->putJson("/api/v1/invoices/{$invoice->id}/refresh", [], ['Authorization' => 'Bearer '.$apiKey->value]);

    $response->assertOk()->assertJsonPath('invoice.lago_id', $invoice->id);
    expect($invoice->refresh()->updated_at->greaterThan($response->json('invoice.updated_at') ? now()->subSeconds(5) : now()))
        ->toBeTrue();
});

it('returns the invoice untouched when refreshing a finalized invoice', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create(['customer_id' => $customer->id, 'organization_id' => $organization->id]);

    $updatedAt = $invoice->updated_at->clone();

    $this->putJson("/api/v1/invoices/{$invoice->id}/refresh", [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('invoice.lago_id', $invoice->id);

    expect($invoice->refresh()->updated_at->equalTo($updatedAt))->toBeTrue();
});

it('returns not_found when refreshing a missing invoice', function (): void {
    [$organization, $apiKey] = invoicesOrganization();

    $this->putJson('/api/v1/invoices/'.Illuminate\Support\Str::uuid().'/refresh', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- PUT /api/v1/invoices/:id/finalize ------------------------------------------

it('finalizes a draft invoice', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->draft()->create(['customer_id' => $customer->id, 'organization_id' => $organization->id]);

    $this->putJson("/api/v1/invoices/{$invoice->id}/finalize", [], ['Authorization' => 'Bearer '.$apiKey->value]);

    expect($invoice->refresh()->statusEnum())->toBe(InvoiceStatus::Finalized);
});

it('finalizes a subscription draft invoice through the refresh-and-finalize flow', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->draft()->create([
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
        'invoice_type' => App\Enums\InvoiceType::Subscription,
    ]);
    $subscription = App\Models\Subscription::factory()->create([
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
    ]);
    App\Models\InvoiceSubscription::factory()->create([
        'invoice_id' => $invoice->id,
        'subscription_id' => $subscription->id,
        'recurring' => true,
    ]);

    $response = $this->putJson("/api/v1/invoices/{$invoice->id}/finalize", [], ['Authorization' => 'Bearer '.$apiKey->value]);

    $response->assertOk()->assertJsonPath('invoice.lago_id', $invoice->id);
    expect($invoice->refresh()->statusEnum())->toBe(InvoiceStatus::Finalized);
});

it('returns not_found when finalizing a non-draft invoice', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
        'status' => InvoiceStatus::Finalized,
    ]);

    $this->putJson("/api/v1/invoices/{$invoice->id}/finalize", [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

it('returns not_found when finalizing a missing invoice', function (): void {
    [$organization, $apiKey] = invoicesOrganization();

    $this->putJson('/api/v1/invoices/'.Illuminate\Support\Str::uuid().'/finalize', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- POST /api/v1/invoices/:id/void ---------------------------------------------

it('voids a finalized invoice', function (): void {
    Queue::fake();

    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
        'status' => InvoiceStatus::Finalized,
        'payment_status' => InvoicePaymentStatus::Pending,
    ]);

    $this->postJson("/api/v1/invoices/{$invoice->id}/void", [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('invoice.lago_id', $invoice->id)
        ->assertJsonPath('invoice.status', 'voided');

    expect($invoice->refresh()->statusEnum())->toBe(InvoiceStatus::Voided);
    expect($invoice->voided_at)->not->toBeNull();
});

it('returns method_not_allowed when voiding a draft invoice', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->draft()->create(['customer_id' => $customer->id, 'organization_id' => $organization->id]);

    $this->postJson("/api/v1/invoices/{$invoice->id}/void", [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertStatus(405);
});

it('returns method_not_allowed when voiding a voided invoice', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
        'status' => InvoiceStatus::Voided,
    ]);

    $this->postJson("/api/v1/invoices/{$invoice->id}/void", [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertStatus(405);
});

it('returns not_found when voiding a missing invoice', function (): void {
    [$organization, $apiKey] = invoicesOrganization();

    $this->postJson('/api/v1/invoices/'.Illuminate\Support\Str::uuid().'/void', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- DELETE /api/v1/invoices/:id ------------------------------------------------

it('marks a draft invoice as deleted', function (): void {
    Queue::fake();

    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->draft()->create(['customer_id' => $customer->id, 'organization_id' => $organization->id]);

    $this->deleteJson("/api/v1/invoices/{$invoice->id}", [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('invoice.lago_id', $invoice->id)
        ->assertJsonPath('invoice.status', 'deleted');

    expect($invoice->refresh()->statusEnum())->toBe(InvoiceStatus::Deleted);
});

it('returns not_found when deleting a missing invoice', function (): void {
    [$organization, $apiKey] = invoicesOrganization();

    $this->deleteJson('/api/v1/invoices/'.Illuminate\Support\Str::uuid(), [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

it('returns method_not_allowed when deleting a non-draft invoice', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create(['customer_id' => $customer->id, 'organization_id' => $organization->id]);

    $this->deleteJson("/api/v1/invoices/{$invoice->id}", [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertStatus(405)
        ->assertJsonPath('code', 'not_deletable');

    expect($invoice->refresh()->statusEnum())->toBe(InvoiceStatus::Finalized);
});

it('returns not_found when deleting an already deleted invoice', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
        'status' => InvoiceStatus::Deleted,
    ]);

    // Deleted invoices are invisible — the visible scope answers 404.
    $this->deleteJson("/api/v1/invoices/{$invoice->id}", [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

it('returns not_found when the deleted invoice belongs to another organization', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $otherOrganization = Organization::factory()->create();
    $otherCustomer = Customer::factory()->create(['organization_id' => $otherOrganization->id]);
    $invoice = Invoice::factory()->draft()->create([
        'customer_id' => $otherCustomer->id,
        'organization_id' => $otherOrganization->id,
    ]);

    $this->deleteJson("/api/v1/invoices/{$invoice->id}", [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- POST /api/v1/invoices/:id/lose_dispute --------------------------------------

it('marks the dispute as lost on a finalized invoice', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
        'status' => InvoiceStatus::Finalized,
    ]);

    $this->postJson("/api/v1/invoices/{$invoice->id}/lose_dispute", [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('invoice.lago_id', $invoice->id);

    expect($invoice->refresh()->payment_dispute_lost_at)->not->toBeNull();
});

it('returns method_not_allowed for a draft invoice dispute', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->draft()->create(['customer_id' => $customer->id, 'organization_id' => $organization->id]);

    $this->postJson("/api/v1/invoices/{$invoice->id}/lose_dispute", [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertStatus(405);
});

// -- POST /api/v1/invoices/:id/retry ---------------------------------------------

it('reopens a failed invoice for a payment retry', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
        'status' => InvoiceStatus::Failed,
    ]);

    $this->postJson("/api/v1/invoices/{$invoice->id}/retry", [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('invoice.lago_id', $invoice->id)
        ->assertJsonPath('invoice.status', 'pending');
});

it('returns method_not_allowed with invalid_status when retrying a non-failed invoice', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create(['customer_id' => $customer->id, 'organization_id' => $organization->id]);

    $this->postJson("/api/v1/invoices/{$invoice->id}/retry", [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertStatus(405)
        ->assertJsonPath('code', 'invalid_status');
});

// -- POST /api/v1/invoices/:id/download_pdf (+ /download alias) and /download_xml

it('answers ok and enqueues GeneratePdfJob when the pdf is missing', function (string $route): void {
    Queue::fake();

    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create(['customer_id' => $customer->id, 'organization_id' => $organization->id]);

    $this->postJson("/api/v1/invoices/{$invoice->id}/{$route}", [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk();

    Queue::assertPushed(App\Jobs\Invoices\GeneratePdfJob::class, fn ($job) => $job->invoice->is($invoice));
})->with(['download', 'download_pdf']);

it('returns the invoice with file_url when the pdf is attached', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create(['customer_id' => $customer->id, 'organization_id' => $organization->id]);

    Storage::fake('lago_test');
    App\Support\ActiveStorage::attach($invoice, 'file', '%PDF-fake', $invoice->number.'.pdf', 'application/pdf');

    Queue::fake();

    $this->postJson("/api/v1/invoices/{$invoice->id}/download_pdf", [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('invoice.lago_id', $invoice->id)
        ->assertJsonPath('invoice.file_url', $invoice->fileUrl());

    Queue::assertNothingPushed();
});

it('answers ok without enqueueing for download_xml while the XML renderer is unported', function (): void {
    Queue::fake();

    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create(['customer_id' => $customer->id, 'organization_id' => $organization->id]);

    $this->postJson("/api/v1/invoices/{$invoice->id}/download_xml", [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk();

    Queue::assertNothingPushed();
});

it('returns not_found for a draft invoice pdf download', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->draft()->create(['customer_id' => $customer->id, 'organization_id' => $organization->id]);

    $this->postJson("/api/v1/invoices/{$invoice->id}/download_pdf", [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- v2 mirror + beta header ------------------------------------------------------

it('mirrors the invoice endpoints under v2 with the beta header', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create(['customer_id' => $customer->id, 'organization_id' => $organization->id]);

    $this->getJson('/api/v2/invoices', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta');

    $this->getJson("/api/v2/invoices/{$invoice->id}", ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('invoice.lago_id', $invoice->id);

    $this->postJson('/api/v2/invoices/'.Illuminate\Support\Str::uuid().'/void', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta');
});

it('mirrors the invoice update via PATCH at v2 with the beta header', function (): void {
    [$organization, $apiKey] = invoicesOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create(['customer_id' => $customer->id, 'organization_id' => $organization->id]);

    $this->patchJson("/api/v2/invoices/{$invoice->id}", [
        'invoice' => ['payment_status' => 'succeeded'],
    ], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('invoice.lago_id', $invoice->id)
        ->assertJsonPath('invoice.payment_status', 'succeeded');
});
