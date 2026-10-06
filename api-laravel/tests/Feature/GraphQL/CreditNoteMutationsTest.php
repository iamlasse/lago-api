<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\Invoice;
use App\Models\CreditNote;
use App\Enums\InvoiceStatus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * Ports of Rails' spec/graphql/{resolvers/credit_notes_resolver_spec.rb and
 * mutations/credit_notes/{download,download_xml,resend_email}_spec.rb} over
 * the frozen SDL.
 *
 * Ledger rows: gql:query:creditNotes, gql:mutation:downloadCreditNote,
 * gql:mutation:downloadXmlCreditNote, gql:mutation:resendCreditNoteEmail.
 */
beforeEach(function (): void {
    Queue::fake();
    Mail::fake();
});

function gqlCreditNoteMutationsSetup(): array
{
    [$organization, $user] = [gqlCreateOrganization(), gqlCreateUser()];
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

function gqlCreditNoteInvoice(object $organization): Invoice
{
    return Invoice::factory()->create([
        'organization_id' => $organization->id,
        'status' => InvoiceStatus::Finalized->value,
        'total_amount_cents' => 2000,
    ]);
}

function gqlCreditNote(object $organization, array $attributes = []): CreditNote
{
    $invoice = $attributes['invoice'] ?? gqlCreditNoteInvoice($organization);

    unset($attributes['invoice']);

    return CreditNote::factory()->forInvoice($invoice)->create(array_merge([
        'organization_id' => $organization->id,
    ], $attributes));
}

const CREDIT_NOTES_LIST_QUERY = <<<'GQL'
query {
    creditNotes(limit: 5) {
        collection { id number creditStatus reason totalAmountCents currency customer { id } invoice { id } }
        metadata { currentPage totalCount }
    }
}
GQL;

it('lists the organization credit notes', function (): void {
    [$organization, $user] = gqlCreditNoteMutationsSetup();

    $creditNote = gqlCreditNote($organization);

    $response = gqlPost(
        CREDIT_NOTES_LIST_QUERY,
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $payload = $response->json('data.creditNotes');

    expect($payload['metadata']['totalCount'])->toBe(1)
        ->and($payload['collection'][0]['id'])->toBe($creditNote->id)
        ->and($payload['collection'][0]['customer']['id'])->toBe($creditNote->customer_id)
        ->and($payload['collection'][0]['invoice']['id'])->toBe($creditNote->invoice_id);
})->group('ledger:gql:query:creditNotes');

it('filters credit notes by currency, customer external id and credit status', function (): void {
    [$organization, $user] = gqlCreditNoteMutationsSetup();

    $available = gqlCreditNote($organization, ['total_amount_currency' => 'EUR']);
    $usd = gqlCreditNote($organization, ['total_amount_currency' => 'USD']);
    $voided = gqlCreditNote($organization, ['credit_status' => 2]); // voided

    $list = static fn (string $args): array => gqlPost(
        "query { creditNotes(limit: 5, {$args}) { collection { id } metadata { totalCount } } }",
        [],
        gqlAuthHeaders($user, $organization->id),
    )->json('data.creditNotes');

    expect(collect($list('currency: EUR')['collection'])->pluck('id')->all())
        ->toContain($available->id)
        ->and(collect($list('currency: USD')['collection'])->pluck('id')->all())->toBe([$usd->id])
        ->and(collect($list('creditStatus: [voided]')['collection'])->pluck('id')->all())->toBe([$voided->id])
        ->and($list('creditStatus: [available]')['metadata']['totalCount'])->toBeGreaterThanOrEqual(1);
})->group('ledger:gql:query:creditNotes');

it('paginates credit notes with the kaminari metadata', function (): void {
    [$organization, $user] = gqlCreditNoteMutationsSetup();

    gqlCreditNote($organization);
    gqlCreditNote($organization);

    $response = gqlPost(
        'query { creditNotes(page: 1, limit: 1) { collection { id } metadata { currentPage limitValue totalPages totalCount } } }',
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.creditNotes.metadata'))->toBe([
        'currentPage' => 1,
        'limitValue' => 1,
        'totalPages' => 2,
        'totalCount' => 2,
    ]);
})->group('ledger:gql:query:creditNotes');

it('answers unauthorized on creditNotes without a token', function (): void {
    $response = gqlPost(CREDIT_NOTES_LIST_QUERY);

    expect($response->json('errors.0.message'))->toBe('unauthorized')
        ->and($response->json('errors.0.extensions.status'))->toBe('unauthorized');
})->group('ledger:gql:query:creditNotes');

const DOWNLOAD_CREDIT_NOTE_MUTATION = <<<'GQL'
mutation($input: DownloadCreditNoteInput!) {
    downloadCreditNote(input: $input) { id number fileUrl }
}
GQL;

it('answers the credit note through downloadCreditNote', function (): void {
    [$organization, $user] = gqlCreditNoteMutationsSetup();

    // LAGO_DISABLE_PDF_GENERATION short-circuits the Gotenberg call, exactly
    // like Rails — the mutation answers the credit note.
    config(['lago.disable_pdf_generation' => true]);

    $creditNote = gqlCreditNote($organization);

    $response = gqlPost(
        DOWNLOAD_CREDIT_NOTE_MUTATION,
        ['input' => ['id' => $creditNote->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.downloadCreditNote.id'))->toBe($creditNote->id)
        ->and($response->json('data.downloadCreditNote.fileUrl'))->toBeNull();

    config(['lago.disable_pdf_generation' => false]);
})->group('ledger:gql:mutation:downloadCreditNote');

it('answers not_found when downloading an unknown credit note', function (): void {
    [$organization, $user] = gqlCreditNoteMutationsSetup();

    $response = gqlPost(
        DOWNLOAD_CREDIT_NOTE_MUTATION,
        ['input' => ['id' => '00000000-0000-0000-0000-000000000000']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions.status'))->toBe(404)
        ->and($response->json('errors.0.extensions.code'))->toBe('not_found');
})->group('ledger:gql:mutation:downloadCreditNote');

const DOWNLOAD_XML_CREDIT_NOTE_MUTATION = <<<'GQL'
mutation($input: DownloadXmlCreditNoteInput!) {
    downloadXmlCreditNote(input: $input) { id number xmlUrl }
}
GQL;

it('answers the credit note through downloadXmlCreditNote', function (): void {
    [$organization, $user] = gqlCreditNoteMutationsSetup();

    // The UBL renderer is a later slice: the service answers success without
    // a file (e-invoicing disabled), like the invoice download_xml endpoint.
    $creditNote = gqlCreditNote($organization);

    $response = gqlPost(
        DOWNLOAD_XML_CREDIT_NOTE_MUTATION,
        ['input' => ['id' => $creditNote->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.downloadXmlCreditNote.id'))->toBe($creditNote->id)
        ->and($response->json('data.downloadXmlCreditNote.xmlUrl'))->toBeNull();
})->group('ledger:gql:mutation:downloadXmlCreditNote');

it('answers the is_draft not_allowed error when downloading a draft credit note XML', function (): void {
    [$organization, $user] = gqlCreditNoteMutationsSetup();

    $creditNote = gqlCreditNote($organization, ['status' => 0]); // draft

    $response = gqlPost(
        DOWNLOAD_XML_CREDIT_NOTE_MUTATION,
        ['input' => ['id' => $creditNote->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions.code'))->toBe('is_draft');
})->group('ledger:gql:mutation:downloadXmlCreditNote');

const RESEND_CREDIT_NOTE_EMAIL_MUTATION = <<<'GQL'
mutation($input: ResendCreditNoteEmailInput!) {
    resendCreditNoteEmail(input: $input) { id }
}
GQL;

it('resends the credit note email with the custom recipients', function (): void {
    [$organization, $user] = gqlCreditNoteMutationsSetup();
    config(['lago.license' => 'premium-license-token']);
    config(['lago.from_email' => 'sender@acme.com']);

    $invoice = gqlCreditNoteInvoice($organization);
    $invoice->billingEntity->update(['email' => 'billing@acme.com']);

    $creditNote = gqlCreditNote($organization, ['invoice' => $invoice]);
    $creditNote->customer->update(['email' => 'owner@acme.com']);

    $response = gqlPost(
        RESEND_CREDIT_NOTE_EMAIL_MUTATION,
        ['input' => ['id' => $creditNote->id, 'to' => ['finance@acme.com']]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.resendCreditNoteEmail.id'))->toBe($creditNote->id)
        ->and($response->json('errors'))->toBeNull();

    config(['lago.license' => null]);
    config(['lago.from_email' => null]);
})->group('ledger:gql:mutation:resendCreditNoteEmail');

it('answers the premium_license_required forbidden error without a license', function (): void {
    [$organization, $user] = gqlCreditNoteMutationsSetup();

    $creditNote = gqlCreditNote($organization);

    $response = gqlPost(
        RESEND_CREDIT_NOTE_EMAIL_MUTATION,
        ['input' => ['id' => $creditNote->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions.code'))->toBe('premium_license_required')
        ->and($response->json('errors.0.extensions.status'))->toBe(403);
})->group('ledger:gql:mutation:resendCreditNoteEmail');

it('scopes the resend lookup to FINALIZED credit notes', function (): void {
    [$organization, $user] = gqlCreditNoteMutationsSetup();
    config(['lago.license' => 'premium-license-token']);

    $creditNote = gqlCreditNote($organization, ['status' => 0]); // draft

    $response = gqlPost(
        RESEND_CREDIT_NOTE_EMAIL_MUTATION,
        ['input' => ['id' => $creditNote->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    // Rails: credit_notes.finalized.find_by(id:) — a draft id is a miss and
    // Emails::ResendService answers not_found on the "resource" type (the
    // class-name lookup only runs when the resource is present).
    expect($response->json('errors.0.extensions'))->toBe([
        'status' => 404,
        'code' => 'not_found',
        'details' => ['resource' => ['not_found']],
    ]);

    config(['lago.license' => null]);
})->group('ledger:gql:mutation:resendCreditNoteEmail');
