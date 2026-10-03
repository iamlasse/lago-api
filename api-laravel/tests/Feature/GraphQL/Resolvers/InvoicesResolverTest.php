<?php

declare(strict_types=1);

require_once __DIR__.'/../GraphQLHelpers.php';
require_once __DIR__.'/../AuthPlumbingTest.php';

use App\Models\Fee;
use App\Models\Plan;
use App\Models\Invoice;
use App\Models\Customer;
use App\Enums\InvoiceType;
use App\Enums\InvoiceStatus;
use App\Models\Subscription;
use App\Models\BillingEntity;
use App\Models\InvoiceAppliedTax;
use App\Enums\InvoicePaymentStatus;
use App\Models\InvoiceSubscription;

/**
 * Ports of Rails' spec/graphql/resolvers/{invoices_resolver,
 * invoice_resolver, invoice_credit_notes_resolver}_spec.rb (the scenarios
 * this slice ports — payments, credit notes, error details, pricing unit
 * usage and presentation breakdowns live with the features that own them)
 * over the frozen SDL.
 *
 * Ledger rows: gql:query:invoices, gql:query:invoice,
 * gql:query:invoiceCreditNotes.
 */
function gqlInvoicesSetup(): array
{
    $organization = gqlCreateOrganization();
    // Rails creates the default billing entity with the organization
    // (Organizations::CreateService); the frozen-schema port creates it
    // explicitly in the fixture — with a real document number prefix so the
    // finalization numbering reads like Rails'.
    $billingEntity = BillingEntity::factory()->create([
        'organization_id' => $organization->id,
        'document_number_prefix' => 'LAGO',
    ]);
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user, $billingEntity];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function gqlMakeInvoice(object $organization, array $attributes = []): Invoice
{
    $customer = $attributes['customer'] ?? Customer::factory()->create([
        'organization_id' => $organization->id,
    ]);

    unset($attributes['customer']);

    return Invoice::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ], $attributes));
}

function gqlMakeInvoiceSubscription(Invoice $invoice, ?Subscription $subscription = null): InvoiceSubscription
{
    return InvoiceSubscription::factory()->create([
        'invoice_id' => $invoice->id,
        'subscription_id' => ($subscription ?? Subscription::factory()->create([
            'organization_id' => $invoice->organization_id,
            'customer_id' => $invoice->customer_id,
        ]))->id,
    ]);
}

const INVOICES_LIST_QUERY = <<<'GQL'
query {
    invoices(limit: 5) {
        collection { id }
        metadata { currentPage totalCount }
    }
}
GQL;

it('returns all invoices', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $first = gqlMakeInvoice($organization, ['payment_status' => InvoicePaymentStatus::Pending->value]);
    $second = gqlMakeInvoice($organization, ['payment_status' => InvoicePaymentStatus::Succeeded->value]);

    $response = gqlPost(
        INVOICES_LIST_QUERY,
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $payload = $response->json('data.invoices');

    expect($payload['collection'])->toHaveCount(2)
        ->and(collect($payload['collection'])->pluck('id')->all())
        ->toEqualCanonicalizing([$first->id, $second->id])
        ->and($payload['metadata']['currentPage'])->toBe(1)
        ->and($payload['metadata']['totalCount'])->toBe(2);
})->group('ledger:gql:query:invoices');

it('filters by payment status, status, invoice type and currency', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $pending = gqlMakeInvoice($organization, ['payment_status' => InvoicePaymentStatus::Pending->value]);
    $succeeded = gqlMakeInvoice($organization, ['payment_status' => InvoicePaymentStatus::Succeeded->value]);
    $draft = gqlMakeInvoice($organization, ['status' => InvoiceStatus::Draft]);
    $oneOff = gqlMakeInvoice($organization, ['invoice_type' => InvoiceType::OneOff]);
    $usd = gqlMakeInvoice($organization, ['currency' => 'USD']);

    $list = static fn (string $args): array => gqlPost(
        "query { invoices(limit: 5, {$args}) { collection { id } metadata { totalCount } } }",
        [],
        gqlAuthHeaders($user, $organization->id),
    )->json('data.invoices');

    expect(collect($list('paymentStatus: [succeeded]')['collection'])->pluck('id')->all())->toBe([$succeeded->id])
        ->and(collect($list('status: [draft]')['collection'])->pluck('id')->all())->toBe([$draft->id])
        ->and(collect($list('invoiceType: [one_off]')['collection'])->pluck('id')->all())->toBe([$oneOff->id])
        ->and(collect($list('currency: USD')['collection'])->pluck('id')->all())->toBe([$usd->id])
        // The defaults stay untouched by the other filters — every factory
        // invoice but the succeeded one carries the default pending status.
        ->and(collect($list('paymentStatus: [pending]')['collection'])->pluck('id')->all())
        ->toEqualCanonicalizing([$pending->id, $draft->id, $oneOff->id, $usd->id]);
})->group('ledger:gql:query:invoices');

it('filters by payment dispute lost and payment overdue', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $disputeLost = gqlMakeInvoice($organization, ['payment_dispute_lost_at' => now()]);
    $overdue = gqlMakeInvoice($organization, ['payment_overdue' => true]);

    $list = static fn (string $args): array => gqlPost(
        "query { invoices(limit: 5, {$args}) { collection { id } metadata { totalCount } } }",
        [],
        gqlAuthHeaders($user, $organization->id),
    )->json('data.invoices');

    expect(collect($list('paymentDisputeLost: true')['collection'])->pluck('id')->all())->toBe([$disputeLost->id])
        ->and(collect($list('paymentDisputeLost: false')['collection'])->pluck('id')->all())
        ->toContain($overdue->id)
        ->and(collect($list('paymentOverdue: true')['collection'])->pluck('id')->all())->toBe([$overdue->id]);
})->group('ledger:gql:query:invoices');

it('filters by partially paid and positive due amount', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    // The factory defaults keep total_amount_cents = total_paid_amount_cents = 0.
    $firstSettled = gqlMakeInvoice($organization);
    $secondSettled = gqlMakeInvoice($organization);
    $partial = gqlMakeInvoice($organization, ['total_amount_cents' => 1000, 'total_paid_amount_cents' => 10]);

    $list = static fn (string $args): array => gqlPost(
        "query { invoices(limit: 5, {$args}) { collection { id } metadata { totalCount } } }",
        [],
        gqlAuthHeaders($user, $organization->id),
    )->json('data.invoices');

    expect(collect($list('partiallyPaid: true')['collection'])->pluck('id')->all())->toBe([$partial->id])
        ->and(collect($list('positiveDueAmount: true')['collection'])->pluck('id')->all())->toBe([$partial->id])
        ->and($list('positiveDueAmount: false')['metadata']['totalCount'])->toBe(2)
        ->and(collect($list('partiallyPaid: false')['collection'])->pluck('id')->all())
        ->toEqualCanonicalizing([$firstSettled->id, $secondSettled->id]);
})->group('ledger:gql:query:invoices');

it('filters by issuing date range', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $older = gqlMakeInvoice($organization, ['issuing_date' => '2026-05-01']);
    gqlMakeInvoice($organization, ['issuing_date' => '2026-06-15']);

    $response = gqlPost(
        'query { invoices(limit: 5, issuingDateFrom: "2026-04-15", issuingDateTo: "2026-05-01") { collection { id } metadata { totalCount } } }',
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    expect(collect($response->json('data.invoices.collection'))->pluck('id')->all())->toBe([$older->id]);
})->group('ledger:gql:query:invoices');

it('filters by amount range including amounts above the 32-bit limit', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $small = gqlMakeInvoice($organization, ['total_amount_cents' => 2_000]);
    $big = gqlMakeInvoice($organization, ['total_amount_cents' => 3_000_000_000]);

    $list = static fn (string $args): array => gqlPost(
        "query { invoices(limit: 5, {$args}) { collection { id } metadata { totalCount } } }",
        [],
        gqlAuthHeaders($user, $organization->id),
    )->json('data.invoices');

    expect(collect($list('amountFrom: 2000, amountTo: 3000')['collection'])->pluck('id')->all())->toBe([$small->id])
        // BigInt wire values travel as strings — the ::numeric cast keeps them exact.
        ->and(collect($list('amountFrom: 0, amountTo: 30000000000')['collection'])->pluck('id')->all())
        ->toEqualCanonicalizing([$small->id, $big->id]);
})->group('ledger:gql:query:invoices');

it('filters by customer id, external customer id, billing entity, subscription and self billed', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $externalCustomer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'external_id' => 'external_id',
    ]);
    $byExternalCustomer = gqlMakeInvoice($organization, ['customer' => $externalCustomer]);

    $billingEntity = BillingEntity::factory()->create(['organization_id' => $organization->id]);
    $byEntity = gqlMakeInvoice($organization, ['billing_entity_id' => $billingEntity->id]);

    $subscription = Subscription::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => Customer::factory()->create(['organization_id' => $organization->id])->id,
        'plan_id' => Plan::factory()->create(['organization_id' => $organization->id])->id,
    ]);
    $bySubscription = gqlMakeInvoice($organization);
    InvoiceSubscription::factory()->create([
        'invoice_id' => $bySubscription->id,
        'subscription_id' => $subscription->id,
        'organization_id' => $organization->id,
    ]);

    $selfBilled = gqlMakeInvoice($organization, ['self_billed' => true, 'status' => InvoiceStatus::Finalized]);

    $list = static fn (string $args): array => gqlPost(
        "query { invoices(limit: 5, {$args}) { collection { id } metadata { totalCount } } }",
        [],
        gqlAuthHeaders($user, $organization->id),
    )->json('data.invoices');

    expect(collect($list('customerExternalId: "external_id"')['collection'])->pluck('id')->all())->toBe([$byExternalCustomer->id])
        ->and(collect($list('customerId: "'.$byExternalCustomer->customer_id.'"')['collection'])->pluck('id')->all())->toBe([$byExternalCustomer->id])
        ->and(collect($list('billingEntityIds: ["'.$billingEntity->id.'"]')['collection'])->pluck('id')->all())->toBe([$byEntity->id])
        ->and(collect($list('subscriptionId: "'.$subscription->id.'"')['collection'])->pluck('id')->all())->toBe([$bySubscription->id])
        ->and(collect($list('selfBilled: true')['collection'])->pluck('id')->all())->toBe([$selfBilled->id]);
})->group('ledger:gql:query:invoices');

it('filters case-insensitively by purchase order number', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $invoice = gqlMakeInvoice($organization, ['purchase_order_number' => 'PO-123']);
    gqlMakeInvoice($organization, ['purchase_order_number' => 'PO-999']);

    $response = gqlPost(
        'query { invoices(limit: 5, purchaseOrderNumber: "po-123") { collection { id } metadata { totalCount } } }',
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    expect(collect($response->json('data.invoices.collection'))->pluck('id')->all())->toBe([$invoice->id]);
})->group('ledger:gql:query:invoices');

it('searches across number, search terms and id', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $numbered = gqlMakeInvoice($organization, ['number' => 'LAGO-202605-001']);
    $named = gqlMakeInvoice($organization, ['customer' => Customer::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Zephyr Deal',
    ])]);
    // search_terms is a computed column refreshed by the pipeline services.
    $numbered->refreshSearchTerms();
    $named->refreshSearchTerms();

    $list = static fn (string $term): array => gqlPost(
        'query($term: String) { invoices(limit: 5, searchTerm: $term) { collection { id } metadata { totalCount } } }',
        ['term' => $term],
        gqlAuthHeaders($user, $organization->id),
    )->json('data.invoices');

    expect(collect($list('LAGO-202605-001')['collection'])->pluck('id')->all())->toBe([$numbered->id])
        ->and(collect($list('zephyr')['collection'])->pluck('id')->all())->toBe([$named->id])
        // The UUID escape hatch matches invoices.id.
        ->and(collect($list($named->id)['collection'])->pluck('id')->all())->toBe([$named->id]);
})->group('ledger:gql:query:invoices');

it('paginates with kaminari defaults and reports the capped metadata', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    gqlMakeInvoice($organization);
    gqlMakeInvoice($organization);

    $response = gqlPost(
        'query { invoices(page: 1, limit: 1) { collection { id } metadata { currentPage limitValue totalPages totalCount totalCountCapped hasNextPage } } }',
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.invoices.metadata'))->toBe([
        'currentPage' => 1,
        'limitValue' => 1,
        'totalPages' => 2,
        'totalCount' => 2,
        'totalCountCapped' => false,
        'hasNextPage' => true,
    ]);

    // An out-of-range page keeps the metadata and reports no next page.
    $outOfRange = gqlPost(
        'query { invoices(page: 3, limit: 1) { collection { id } metadata { currentPage totalPages hasNextPage } } }',
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($outOfRange->json('data.invoices.collection'))->toBe([])
        ->and($outOfRange->json('data.invoices.metadata.hasNextPage'))->toBeFalse();
})->group('ledger:gql:query:invoices');

it('returns an error for invalid filters', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $response = gqlPost(
        'query { invoices(limit: 5, billingEntityIds: ["random"]) { collection { id } metadata { totalCount } } }',
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.message'))->toBe('Unprocessable Entity')
        ->and($response->json('errors.0.extensions.code'))->toBe('unprocessable_entity')
        ->and($response->json('errors.0.extensions.details'))->toBe(['billingEntityIds' => ['is invalid']]);
})->group('ledger:gql:query:invoices');

it('returns unauthorized on invoices without a token', function (): void {
    $response = gqlPost(INVOICES_LIST_QUERY);

    expect($response->json('errors.0.message'))->toBe('unauthorized')
        ->and($response->json('errors.0.extensions.status'))->toBe('unauthorized');
})->group('ledger:gql:query:invoices');

const INVOICE_QUERY = <<<'GQL'
query($id: ID!) {
    invoice(id: $id) {
        id
        number
        sequentialId
        status
        invoiceType
        paymentStatus
        taxStatus
        paymentDisputeLosable
        voidable
        payableType
        currency
        taxesRate
        feesAmountCents
        couponsAmountCents
        totalAmountCents
        totalPaidAmountCents
        totalDueAmountCents
        totalSettledAmountCents
        issuingDate
        paymentDueDate
        createdAt
        customer { id name }
        billingEntity { id }
        fees { id amountCents amountCurrency }
        appliedTaxes { id taxCode taxName taxRate amountCents }
        invoiceSubscriptions { chargesFromDatetime subscription { id } }
        subscriptions { id }
    }
}
GQL;

it('returns a single invoice with computed fields', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = gqlMakeInvoice($organization, [
        'customer' => $customer,
        'number' => 'LAGO-202605-001',
        'total_amount_cents' => 1100,
        'total_paid_amount_cents' => 100,
        'fees_amount_cents' => 10,
        'taxes_rate' => 20.0,
    ]);

    $tax = InvoiceAppliedTax::factory()->create([
        'invoice_id' => $invoice->id,
        'tax_name' => 'VAT',
        'tax_code' => 'lago_tax',
        'tax_rate' => 20.0,
        'amount_cents' => 183,
        'amount_currency' => 'EUR',
    ]);

    $subscription = Subscription::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'plan_id' => Plan::factory()->create(['organization_id' => $organization->id])->id,
    ]);
    InvoiceSubscription::factory()->create([
        'invoice_id' => $invoice->id,
        'subscription_id' => $subscription->id,
        'organization_id' => $organization->id,
    ]);

    $response = gqlPost(
        INVOICE_QUERY,
        ['id' => $invoice->id],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $payload = $response->json('data.invoice');

    expect($payload['id'])->toBe($invoice->id)
        ->and($payload['number'])->toBe('LAGO-202605-001')
        ->and($payload['status'])->toBe('finalized')
        ->and($payload['invoiceType'])->toBe('subscription')
        ->and($payload['paymentStatus'])->toBe('pending')
        ->and($payload['taxStatus'])->toBe('succeeded')
        ->and($payload['paymentDisputeLosable'])->toBeTrue()
        // Something was already paid — Rails' voidable? returns false.
        ->and($payload['voidable'])->toBeFalse()
        ->and($payload['payableType'])->toBe('Invoice')
        ->and($payload['currency'])->toBe('EUR')
        ->and($payload['feesAmountCents'])->toBe('10')
        ->and($payload['totalAmountCents'])->toBe('1100')
        ->and($payload['totalDueAmountCents'])->toBe('1000')
        ->and($payload['totalSettledAmountCents'])->toBe('100')
        ->and($payload['issuingDate'])->toMatch('/^'.now()->toDateString().'T00:00:00Z$/')
        ->and($payload['customer']['id'])->toBe($customer->id)
        ->and($payload['billingEntity']['id'])->toBe($invoice->billing_entity_id)
        ->and($payload['appliedTaxes'][0]['id'])->toBe($tax->id)
        ->and($payload['appliedTaxes'][0]['taxName'])->toBe('VAT')
        ->and($payload['appliedTaxes'][0]['taxRate'])->toEqual(20.0)
        ->and($payload['invoiceSubscriptions'][0]['subscription']['id'])->toBe($subscription->id)
        ->and($payload['subscriptions'][0]['id'])->toBe($subscription->id);
})->group('ledger:gql:query:invoice');

it('returns fees on the invoice', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $invoice = gqlMakeInvoice($organization);
    $fee = Fee::factory()->chargeFee()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $organization->id,
        'amount_cents' => 10,
        'amount_currency' => 'EUR',
        'taxes_amount_cents' => 2,
    ]);

    $response = gqlPost(
        'query($id: ID!) { invoice(id: $id) { fees { id amountCents amountCurrency taxesAmountCents } } }',
        ['id' => $invoice->id],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.invoice.fees'))->toBe([[
        'id' => $fee->id,
        'amountCents' => '10',
        'amountCurrency' => 'EUR',
        'taxesAmountCents' => '2',
    ]]);
})->group('ledger:gql:query:invoice');

it('hides invoices in the invisible statuses behind the not_found envelope', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $closed = gqlMakeInvoice($organization, ['status' => InvoiceStatus::Closed]);

    $response = gqlPost(
        INVOICE_QUERY,
        ['id' => $closed->id],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.invoice'))->toBeNull()
        ->and($response->json('errors.0.message'))->toBe('Resource not found')
        ->and($response->json('errors.0.extensions'))->toBe([
            'status' => 404,
            'code' => 'not_found',
            'details' => ['invoice' => ['not_found']],
        ]);
})->group('ledger:gql:query:invoice');

it('returns the not_found envelope for an unknown invoice', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $response = gqlPost(
        INVOICE_QUERY,
        ['id' => '00000000-0000-0000-0000-000000000000'],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.invoice'))->toBeNull()
        ->and($response->json('errors.0.message'))->toBe('Resource not found')
        ->and($response->json('errors.0.extensions.details'))->toBe(['invoice' => ['not_found']]);
})->group('ledger:gql:query:invoice');

it('returns unauthorized on a single invoice without a token', function (): void {
    $response = gqlPost('query { invoice(id: "anything") { id } }');

    expect($response->json('errors.0.message'))->toBe('unauthorized')
        ->and($response->json('errors.0.extensions.status'))->toBe('unauthorized');
})->group('ledger:gql:query:invoice');

it('resolves invoiceCreditNotes on an invoice without credit notes (the stub is gone)', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $invoice = gqlMakeInvoice($organization);

    $response = gqlPost(
        'query($invoiceId: ID!) { invoiceCreditNotes(invoiceId: $invoiceId) { collection { id } metadata { currentPage } } }',
        ['invoiceId' => $invoice->id],
        gqlAuthHeaders($user, $organization->id),
    );

    // The real resolver (App\GraphQL\Queries\InvoiceCreditNotes) shipped with
    // the credit-notes slice — an empty collection with kaminari metadata.
    $payload = $response->json('data.invoiceCreditNotes');

    expect($payload['collection'])->toHaveCount(0)
        ->and($payload['metadata']['currentPage'])->toBe(1);
})->group('ledger:gql:query:invoiceCreditNotes');
