<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\Fee;
use App\Models\Tax;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\CreditNote;
use App\Models\BillingEntity;
use App\Models\FeeAppliedTax;
use App\Models\CreditNoteItem;
use App\Models\InvoiceAppliedTax;
use App\Enums\InvoicePaymentStatus;

/**
 * Ports of Rails' spec/graphql/resolvers/{credit_note_resolver,
 * invoice_credit_notes_resolver, credit_notes/estimate_resolver}_spec.rb and
 * spec/graphql/mutations/credit_notes/*_spec.rb over the frozen SDL.
 *
 * Ledger rows: gql:query:invoiceCreditNotes, gql:query:creditNote,
 * gql:query:creditNoteEstimate, gql:mutation:createCreditNote,
 * gql:mutation:updateCreditNote, gql:mutation:voidCreditNote.
 */
function gqlCreditNotesSetup(): array
{
    $organization = gqlCreateOrganization();
    BillingEntity::factory()->create([
        'organization_id' => $organization->id,
        'document_number_prefix' => 'LAGO',
    ]);
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

function gqlCreditNotesInvoice(object $organization, array $attributes = []): Invoice
{
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
    ]);

    return Invoice::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'number' => 'LAGO-202610-042',
        'currency' => 'EUR',
        'fees_amount_cents' => 20,
        'taxes_amount_cents' => 4,
        'total_amount_cents' => 24,
        'total_paid_amount_cents' => 24,
        'sub_total_excluding_taxes_amount_cents' => 20,
        'sub_total_including_taxes_amount_cents' => 24,
        'payment_status' => InvoicePaymentStatus::Succeeded,
        'version_number' => 3,
    ], $attributes));
}

function gqlCreditNotesFee(Invoice $invoice, array $attributes = []): Fee
{
    return Fee::factory()->create(array_merge([
        'invoice_id' => $invoice->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => 10,
        'precise_amount_cents' => 10,
        'taxes_amount_cents' => 2,
        'taxes_rate' => 20,
    ], $attributes));
}

function gqlCreditNotesTaxes(Invoice $invoice, Fee $fee, float $rate = 20.0): Tax
{
    $tax = Tax::factory()->create([
        'organization_id' => $invoice->organization_id,
        'rate' => $rate,
    ]);

    FeeAppliedTax::factory()->create([
        'fee_id' => $fee->id,
        'tax_id' => $tax->id,
        'organization_id' => $invoice->organization_id,
    ]);

    InvoiceAppliedTax::factory()->create([
        'invoice_id' => $invoice->id,
        'tax_id' => $tax->id,
        'organization_id' => $invoice->organization_id,
    ]);

    return $tax;
}

const INVOICE_CREDIT_NOTES_QUERY = <<<'GQL'
query($invoiceId: ID!) {
    invoiceCreditNotes(invoiceId: $invoiceId) {
        collection {
            id
            number
            creditStatus
            refundStatus
            reason
            currency
            totalAmountCents
            balanceAmountCents
            subTotalExcludingTaxesAmountCents
            canBeVoided
            invoice { id }
            customer { id }
        }
        metadata { currentPage limitValue totalPages totalCount }
    }
}
GQL;

it('returns the invoice finalized credit notes, newest first', function (): void {
    [$organization, $user] = gqlCreditNotesSetup();
    $invoice = gqlCreditNotesInvoice($organization);

    $older = CreditNote::factory()->forInvoice($invoice)->create([
        'created_at' => now()->subDay(),
        'number' => 'LAGO-202610-042-CN001',
        'sequential_id' => 1,
    ]);
    $newer = CreditNote::factory()->forInvoice($invoice)->create([
        'number' => 'LAGO-202610-042-CN002',
        'sequential_id' => 2,
    ]);

    $response = gqlPost(
        INVOICE_CREDIT_NOTES_QUERY,
        ['invoiceId' => $invoice->id],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $payload = $response->json('data.invoiceCreditNotes');

    expect(collect($payload['collection'])->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and($payload['collection'][0]['number'])->toBe('LAGO-202610-042-CN002')
        ->and($payload['collection'][0]['creditStatus'])->toBe('available')
        ->and($payload['collection'][0]['reason'])->toBe('duplicated_charge')
        ->and($payload['collection'][0]['currency'])->toBe('EUR')
        ->and($payload['collection'][0]['totalAmountCents'])->toBe('120')
        ->and($payload['collection'][0]['canBeVoided'])->toBeTrue()
        ->and($payload['collection'][0]['invoice']['id'])->toBe($invoice->id)
        ->and($payload['collection'][0]['customer']['id'])->toBe($invoice->customer_id)
        ->and($payload['metadata']['currentPage'])->toBe(1)
        ->and($payload['metadata']['totalCount'])->toBe(2);
})->group('ledger:gql:query:invoiceCreditNotes');

it('hides draft credit notes from the invoice credit notes query', function (): void {
    [$organization, $user] = gqlCreditNotesSetup();
    $invoice = gqlCreditNotesInvoice($organization);

    $draft = CreditNote::factory()->forInvoice($invoice)->draft()->create();

    $response = gqlPost(
        INVOICE_CREDIT_NOTES_QUERY,
        ['invoiceId' => $invoice->id],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.invoiceCreditNotes');

    expect($payload['collection'])->toHaveCount(0)
        ->and($payload['metadata']['totalCount'])->toBe(0);
})->group('ledger:gql:query:invoiceCreditNotes');

it('paginates the invoice credit notes', function (): void {
    [$organization, $user] = gqlCreditNotesSetup();
    $invoice = gqlCreditNotesInvoice($organization);

    foreach (range(1, 3) as $i) {
        CreditNote::factory()->forInvoice($invoice)->create([
            'number' => 'LAGO-202610-042-CN00'.$i,
            'sequential_id' => $i,
            'created_at' => now()->addSeconds($i),
        ]);
    }

    $response = gqlPost(
        'query($invoiceId: ID!) { invoiceCreditNotes(invoiceId: $invoiceId, page: 2, limit: 2) { collection { id } metadata { currentPage limitValue totalPages totalCount } } }',
        ['invoiceId' => $invoice->id],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.invoiceCreditNotes');

    expect($payload['collection'])->toHaveCount(1)
        ->and($payload['metadata']['currentPage'])->toBe(2)
        ->and($payload['metadata']['limitValue'])->toBe(2)
        ->and($payload['metadata']['totalPages'])->toBe(2)
        ->and($payload['metadata']['totalCount'])->toBe(3);
})->group('ledger:gql:query:invoiceCreditNotes');

it('returns the not_found envelope for an unknown invoice', function (): void {
    [$organization, $user] = gqlCreditNotesSetup();

    $response = gqlPost(
        INVOICE_CREDIT_NOTES_QUERY,
        ['invoiceId' => '00000000-0000-0000-0000-000000000000'],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.invoiceCreditNotes'))->toBeNull()
        ->and($response->json('errors.0.message'))->toBe('Resource not found')
        ->and($response->json('errors.0.extensions.details'))->toBe(['invoice' => ['not_found']]);
})->group('ledger:gql:query:invoiceCreditNotes');

it('returns unauthorized on the invoice credit notes query without a token', function (): void {
    $response = gqlPost('query { invoiceCreditNotes(invoiceId: "x") { collection { id } } }');

    expect($response->json('errors.0.message'))->toBe('unauthorized')
        ->and($response->json('errors.0.extensions.status'))->toBe('unauthorized');
})->group('ledger:gql:query:invoiceCreditNotes');

const CREDIT_NOTE_QUERY = <<<'GQL'
query($id: ID!) {
    creditNote(id: $id) {
        id
        number
        sequentialId
        issuingDate
        creditStatus
        refundStatus
        reason
        taxesRate
        totalAmountCents
        taxesAmountCents
        appliedTaxes { taxCode taxRate amountCents }
        items { amountCents amountCurrency fee { id } }
    }
}
GQL;

it('queries a single finalized credit note', function (): void {
    [$organization, $user] = gqlCreditNotesSetup();
    $invoice = gqlCreditNotesInvoice($organization);
    $fee = gqlCreditNotesFee($invoice);

    $creditNote = CreditNote::factory()->forInvoice($invoice)->create([
        'number' => 'LAGO-202610-042-CN001',
        'taxes_rate' => 20.0,
        'taxes_amount_cents' => 2,
        'total_amount_cents' => 12,
    ]);
    CreditNoteItem::factory()->create([
        'credit_note_id' => $creditNote->id,
        'fee_id' => $fee->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => 10,
    ]);

    $response = gqlPost(
        CREDIT_NOTE_QUERY,
        ['id' => $creditNote->id],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.creditNote');

    expect($payload['id'])->toBe($creditNote->id)
        ->and($payload['number'])->toBe('LAGO-202610-042-CN001')
        ->and($payload['sequentialId'])->toBe((string) $creditNote->sequential_id)
        ->and($payload['reason'])->toBe('duplicated_charge')
        ->and($payload['taxesRate'])->toEqual(20.0)
        ->and($payload['totalAmountCents'])->toBe('12')
        ->and($payload['items'][0]['amountCents'])->toBe('10')
        ->and($payload['items'][0]['fee']['id'])->toBe($fee->id);
})->group('ledger:gql:query:creditNote');

it('hides draft credit notes from the single credit note query', function (): void {
    [$organization, $user] = gqlCreditNotesSetup();
    $invoice = gqlCreditNotesInvoice($organization);
    $draft = CreditNote::factory()->forInvoice($invoice)->draft()->create();

    $response = gqlPost(
        CREDIT_NOTE_QUERY,
        ['id' => $draft->id],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.creditNote'))->toBeNull()
        ->and($response->json('errors.0.message'))->toBe('Resource not found')
        ->and($response->json('errors.0.extensions.details'))->toBe(['creditNote' => ['not_found']]);
})->group('ledger:gql:query:creditNote');

const CREATE_CREDIT_NOTE_MUTATION = <<<'GQL'
mutation($input: CreateCreditNoteInput!) {
    createCreditNote(input: $input) {
        id
        number
        invoice { id }
        creditAmountCents
        refundAmountCents
        totalAmountCents
        taxesAmountCents
        creditStatus
        refundStatus
        reason
    }
}
GQL;

function gqlCreditNotesWithLicense(callable $body): void
{
    config(['lago.license' => 'premium']);
    try {
        $body();
    } finally {
        config(['lago.license' => null]);
    }
}

it('creates a credit note', function (): void {
    gqlCreditNotesWithLicense(function (): void {
        [$organization, $user] = gqlCreditNotesSetup();
        $invoice = gqlCreditNotesInvoice($organization);
        $fee1 = gqlCreditNotesFee($invoice);
        $fee2 = gqlCreditNotesFee($invoice);
        gqlCreditNotesTaxes($invoice, $fee1);
        gqlCreditNotesTaxes($invoice, $fee2);

        $response = gqlPost(
            CREATE_CREDIT_NOTE_MUTATION,
            ['input' => [
                'invoiceId' => $invoice->id,
                'reason' => 'duplicated_charge',
                'creditAmountCents' => 12,
                'refundAmountCents' => 6,
                'items' => [
                    ['feeId' => $fee1->id, 'amountCents' => 10],
                    ['feeId' => $fee2->id, 'amountCents' => 5],
                ],
            ]],
            gqlAuthHeaders($user, $organization->id),
        );

        $response->assertOk();

        $payload = $response->json('data.createCreditNote');

        expect($payload)->not->toBeNull()
            ->and($payload['invoice']['id'])->toBe($invoice->id)
            ->and($payload['number'])->toBe('LAGO-202610-042-CN001')
            ->and($payload['creditAmountCents'])->toBe('12')
            ->and($payload['refundAmountCents'])->toBe('6')
            ->and($payload['totalAmountCents'])->toBe('18')
            ->and($payload['taxesAmountCents'])->toBe('3')
            ->and($payload['creditStatus'])->toBe('available')
            ->and($payload['refundStatus'])->toBe('pending')
            ->and($payload['reason'])->toBe('duplicated_charge');

        expect(CreditNote::query()->where('invoice_id', $invoice->id)->count())->toBe(1);
    });
})->group('ledger:gql:mutation:createCreditNote');

it('rejects a credit note whose amounts do not match the items', function (): void {
    gqlCreditNotesWithLicense(function (): void {
        [$organization, $user] = gqlCreditNotesSetup();
        $invoice = gqlCreditNotesInvoice($organization);
        $fee = gqlCreditNotesFee($invoice);
        gqlCreditNotesTaxes($invoice, $fee);

        $response = gqlPost(
            CREATE_CREDIT_NOTE_MUTATION,
            ['input' => [
                'invoiceId' => $invoice->id,
                'reason' => 'other',
                'creditAmountCents' => 20,
                'items' => [['feeId' => $fee->id, 'amountCents' => 5]],
            ]],
            gqlAuthHeaders($user, $organization->id),
        );

        expect($response->json('data.createCreditNote'))->toBeNull()
            ->and($response->json('errors.0.extensions.details'))->toBe([
                'base' => ['does_not_match_item_amounts'],
            ]);
    });
})->group('ledger:gql:mutation:createCreditNote');

const UPDATE_CREDIT_NOTE_MUTATION = <<<'GQL'
mutation($input: UpdateCreditNoteInput!) {
    updateCreditNote(input: $input) {
        id
        refundStatus
        refundedAt
    }
}
GQL;

it('updates the credit note refund status', function (): void {
    [$organization, $user] = gqlCreditNotesSetup();
    $invoice = gqlCreditNotesInvoice($organization);
    $creditNote = CreditNote::factory()->forInvoice($invoice)->create();

    $response = gqlPost(
        UPDATE_CREDIT_NOTE_MUTATION,
        ['input' => ['id' => $creditNote->id, 'refundStatus' => 'succeeded']],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.updateCreditNote');

    expect($payload['id'])->toBe($creditNote->id)
        ->and($payload['refundStatus'])->toBe('succeeded')
        ->and($payload['refundedAt'])->not->toBeNull();
})->group('ledger:gql:mutation:updateCreditNote');

it('returns not_found when updating an unknown credit note', function (): void {
    [$organization, $user] = gqlCreditNotesSetup();

    $response = gqlPost(
        UPDATE_CREDIT_NOTE_MUTATION,
        ['input' => ['id' => '00000000-0000-0000-0000-000000000000', 'refundStatus' => 'succeeded']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.updateCreditNote'))->toBeNull()
        ->and($response->json('errors.0.extensions.details'))->toBe(['creditNote' => ['not_found']]);
})->group('ledger:gql:mutation:updateCreditNote');

it('returns not_found when updating a draft credit note', function (): void {
    [$organization, $user] = gqlCreditNotesSetup();
    $invoice = gqlCreditNotesInvoice($organization);
    $draft = CreditNote::factory()->forInvoice($invoice)->draft()->create();

    $response = gqlPost(
        UPDATE_CREDIT_NOTE_MUTATION,
        ['input' => ['id' => $draft->id, 'refundStatus' => 'succeeded']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.updateCreditNote'))->toBeNull()
        ->and($response->json('errors.0.extensions.details'))->toBe(['creditNote' => ['not_found']]);
})->group('ledger:gql:mutation:updateCreditNote');

const VOID_CREDIT_NOTE_MUTATION = <<<'GQL'
mutation($input: VoidCreditNoteInput!) {
    voidCreditNote(input: $input) {
        id
        creditStatus
        voidedAt
        balanceAmountCents
    }
}
GQL;

it('voids a credit note', function (): void {
    [$organization, $user] = gqlCreditNotesSetup();
    $invoice = gqlCreditNotesInvoice($organization);
    $creditNote = CreditNote::factory()->forInvoice($invoice)->create([
        'balance_amount_cents' => 50,
    ]);

    $response = gqlPost(
        VOID_CREDIT_NOTE_MUTATION,
        ['input' => ['id' => $creditNote->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.voidCreditNote');

    expect($payload['id'])->toBe($creditNote->id)
        ->and($payload['creditStatus'])->toBe('voided')
        ->and($payload['voidedAt'])->not->toBeNull()
        ->and($payload['balanceAmountCents'])->toBe('0');
})->group('ledger:gql:mutation:voidCreditNote');

it('rejects voiding an already voided credit note', function (): void {
    [$organization, $user] = gqlCreditNotesSetup();
    $invoice = gqlCreditNotesInvoice($organization);
    $creditNote = CreditNote::factory()->forInvoice($invoice)->create([
        'credit_status' => App\Enums\CreditNoteCreditStatus::Voided,
        'voided_at' => now(),
    ]);

    $response = gqlPost(
        VOID_CREDIT_NOTE_MUTATION,
        ['input' => ['id' => $creditNote->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.voidCreditNote'))->toBeNull()
        ->and($response->json('errors.0.message'))->toBe('Method Not Allowed')
        ->and($response->json('errors.0.extensions.code'))->toBe('no_voidable_amount');
})->group('ledger:gql:mutation:voidCreditNote');

const CREDIT_NOTE_ESTIMATE_QUERY = <<<'GQL'
query($invoiceId: ID!, $items: [CreditNoteItemInput!]!) {
    creditNoteEstimate(invoiceId: $invoiceId, items: $items) {
        currency
        taxesAmountCents
        taxesRate
        preciseTaxesAmountCents
        maxCreditableAmountCents
        maxRefundableAmountCents
        maxOffsettableAmountCents
        subTotalExcludingTaxesAmountCents
        appliedTaxes { taxCode taxRate amountCents }
        items { amountCents fee { id } }
    }
}
GQL;

it('estimates the credit note amounts', function (): void {
    gqlCreditNotesWithLicense(function (): void {
        [$organization, $user] = gqlCreditNotesSetup();
        $invoice = gqlCreditNotesInvoice($organization);
        $fee = gqlCreditNotesFee($invoice);
        gqlCreditNotesTaxes($invoice, $fee);

        $response = gqlPost(
            CREDIT_NOTE_ESTIMATE_QUERY,
            ['invoiceId' => $invoice->id, 'items' => [['feeId' => $fee->id, 'amountCents' => 10]]],
            gqlAuthHeaders($user, $organization->id),
        );

        $payload = $response->json('data.creditNoteEstimate');

        expect($payload)->not->toBeNull()
            ->and($payload['currency'])->toBe('EUR')
            ->and($payload['taxesAmountCents'])->toBe('2')
            ->and($payload['taxesRate'])->toEqual(20.0)
            ->and($payload['maxCreditableAmountCents'])->toBe('12')
            ->and($payload['maxRefundableAmountCents'])->toBe('12')
            ->and($payload['subTotalExcludingTaxesAmountCents'])->toBe('10')
            ->and($payload['appliedTaxes'][0]['taxCode'])->toBe(Tax::query()->where('organization_id', $organization->id)->first()->code)
            ->and($payload['items'][0]['fee']['id'])->toBe($fee->id);
    });
})->group('ledger:gql:query:creditNoteEstimate');

it('returns not_found when estimating for an unknown invoice', function (): void {
    gqlCreditNotesWithLicense(function (): void {
        [$organization, $user] = gqlCreditNotesSetup();

        $response = gqlPost(
            CREDIT_NOTE_ESTIMATE_QUERY,
            ['invoiceId' => '00000000-0000-0000-0000-000000000000', 'items' => []],
            gqlAuthHeaders($user, $organization->id),
        );

        expect($response->json('data.creditNoteEstimate'))->toBeNull()
            ->and($response->json('errors.0.extensions.details'))->toBe(['invoice' => ['not_found']]);
    });
})->group('ledger:gql:query:creditNoteEstimate');
