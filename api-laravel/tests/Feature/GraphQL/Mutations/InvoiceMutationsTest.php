<?php

declare(strict_types=1);

require_once __DIR__.'/../GraphQLHelpers.php';
require_once __DIR__.'/../AuthPlumbingTest.php';
require_once __DIR__.'/../Resolvers/InvoicesResolverTest.php';

use App\Models\Invoice;
use App\Enums\InvoiceStatus;

/**
 * Ports of Rails' spec/graphql/mutations/invoices/{finalize,delete,void,
 * refresh,retry,download,...}_spec.rb over the frozen SDL.
 *
 * Ledger rows: gql:mutation:finalizeInvoice, gql:mutation:deleteInvoice,
 * gql:mutation:voidInvoice, gql:mutation:refreshInvoice,
 * gql:mutation:retryInvoice, gql:mutation:downloadInvoice.
 */
const FINALIZE_INVOICE_MUTATION = <<<'GQL'
mutation($input: FinalizeInvoiceInput!) {
    finalizeInvoice(input: $input) {
        id
        number
        status
        sequentialId
        voidable
    }
}
GQL;

it('finalizes the given draft invoice', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $invoice = gqlMakeInvoice($organization, ['status' => InvoiceStatus::Draft->value, 'number' => null]);

    $response = gqlPost(
        FINALIZE_INVOICE_MUTATION,
        ['input' => ['id' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $payload = $response->json('data.finalizeInvoice');

    expect($payload['id'])->toBe($invoice->id)
        ->and($payload['status'])->toBe('finalized')
        // The draft placeholder gives way to the per-customer document number.
        ->and($payload['number'])->toMatch('/^LAGO-\d{3}-\d{3}$/')
        ->and($payload['sequentialId'])->toBeString()
        // Nothing paid and payment pending — Rails' voidable? flips to true.
        ->and($payload['voidable'])->toBeTrue();

    $invoice->refresh();

    expect($invoice->statusEnum()?->label())->toBe('finalized')
        ->and($invoice->finalized_at)->not->toBeNull();
})->group('ledger:gql:mutation:finalizeInvoice');

it('answers the not_found envelope for an already finalized invoice (draft-only lookup)', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $invoice = gqlMakeInvoice($organization, ['status' => InvoiceStatus::Finalized->value]);

    // Rails looks the invoice up among DRAFT invoices only, so a finalized
    // id answers with the not_found envelope — not with the invoice.
    $response = gqlPost(
        FINALIZE_INVOICE_MUTATION,
        ['input' => ['id' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.finalizeInvoice'))->toBeNull()
        ->and($response->json('errors.0.message'))->toBe('Resource not found')
        ->and($response->json('errors.0.extensions'))->toBe([
            'status' => 404,
            'code' => 'not_found',
            'details' => ['invoice' => ['not_found']],
        ]);
})->group('ledger:gql:mutation:finalizeInvoice');

it('finalizeInvoice returns the not_found envelope for an unknown invoice', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $response = gqlPost(
        FINALIZE_INVOICE_MUTATION,
        ['input' => ['id' => '00000000-0000-0000-0000-000000000000']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.finalizeInvoice'))->toBeNull()
        ->and($response->json('errors.0.message'))->toBe('Resource not found')
        ->and($response->json('errors.0.extensions.details'))->toBe(['invoice' => ['not_found']]);
})->group('ledger:gql:mutation:finalizeInvoice');

it('finalizeInvoice returns unauthorized without a token', function (): void {
    $response = gqlPost(FINALIZE_INVOICE_MUTATION, ['input' => ['id' => 'anything']]);

    expect($response->json('errors.0.message'))->toBe('unauthorized')
        ->and($response->json('errors.0.extensions.status'))->toBe('unauthorized');
})->group('ledger:gql:mutation:finalizeInvoice');

it('deletes a draft invoice through deleteInvoice', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $invoice = gqlMakeInvoice($organization, ['status' => InvoiceStatus::Draft->value]);

    $response = gqlPost(
        'mutation($input: DeleteInvoiceInput!) { deleteInvoice(input: $input) { id status } }',
        ['input' => ['id' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.deleteInvoice.id'))->toBe($invoice->id)
        ->and($invoice->refresh()->statusEnum()?->label())->toBe('deleted');
})->group('ledger:gql:mutation:deleteInvoice');

it('answers the not_deletable not_allowed envelope when the invoice is not a draft', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $invoice = gqlMakeInvoice($organization, ['status' => InvoiceStatus::Finalized->value]);

    $response = gqlPost(
        'mutation($input: DeleteInvoiceInput!) { deleteInvoice(input: $input) { id } }',
        ['input' => ['id' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.deleteInvoice'))->toBeNull()
        ->and($response->json('errors.0.extensions.code'))->toBe('not_deletable');
})->group('ledger:gql:mutation:deleteInvoice');

it('answers the not_found envelope for an unknown invoice on deleteInvoice', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $response = gqlPost(
        'mutation($input: DeleteInvoiceInput!) { deleteInvoice(input: $input) { id } }',
        ['input' => ['id' => '00000000-0000-0000-0000-000000000000']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions'))->toBe([
        'status' => 404,
        'code' => 'not_found',
        'details' => ['invoice' => ['not_found']],
    ]);
})->group('ledger:gql:mutation:deleteInvoice');

it('voids the invoice through voidInvoice', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $invoice = gqlMakeInvoice($organization, [
        'status' => InvoiceStatus::Finalized->value,
        'total_amount_cents' => 1000,
        'total_paid_amount_cents' => 0,
    ]);

    $response = gqlPost(
        'mutation($input: VoidInvoiceInput!) { voidInvoice(input: $input) { id status voidable } }',
        ['input' => ['id' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.voidInvoice.id'))->toBe($invoice->id)
        ->and($response->json('data.voidInvoice.status'))->toBe('voided')
        ->and($invoice->refresh()->statusEnum()?->label())->toBe('voided');
})->group('ledger:gql:mutation:voidInvoice');

it('answers the not_allowed envelope when the invoice is not voidable', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    // A draft invoice is not voidable (Rails: voidable? requires finalized).
    $invoice = gqlMakeInvoice($organization, ['status' => InvoiceStatus::Draft->value]);

    $response = gqlPost(
        'mutation($input: VoidInvoiceInput!) { voidInvoice(input: $input) { id } }',
        ['input' => ['id' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.voidInvoice'))->toBeNull()
        ->and($response->json('errors.0.extensions.code'))->toBe('not_voidable');
})->group('ledger:gql:mutation:voidInvoice');

it('refreshes a draft invoice through refreshInvoice', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $invoice = gqlMakeInvoice($organization, ['status' => InvoiceStatus::Draft->value]);

    $response = gqlPost(
        'mutation($input: RefreshInvoiceInput!) { refreshInvoice(input: $input) { id status } }',
        ['input' => ['id' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.refreshInvoice.id'))->toBe($invoice->id)
        ->and($response->json('data.refreshInvoice.status'))->toBe('draft');
})->group('ledger:gql:mutation:refreshInvoice');

it('answers the not_found envelope for an unknown invoice on refreshInvoice', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $response = gqlPost(
        'mutation($input: RefreshInvoiceInput!) { refreshInvoice(input: $input) { id } }',
        ['input' => ['id' => '00000000-0000-0000-0000-000000000000']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions'))->toBe([
        'status' => 404,
        'code' => 'not_found',
        'details' => ['invoice' => ['not_found']],
    ]);
})->group('ledger:gql:mutation:refreshInvoice');

it('retries a failed invoice through retryInvoice', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $invoice = gqlMakeInvoice($organization, ['status' => InvoiceStatus::Failed->value]);

    $response = gqlPost(
        'mutation($input: RetryInvoiceInput!) { retryInvoice(input: $input) { id status } }',
        ['input' => ['id' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.retryInvoice.id'))->toBe($invoice->id)
        ->and($response->json('data.retryInvoice.status'))->toBe('pending');

    expect($invoice->refresh()->statusEnum()?->label())->toBe('pending');
})->group('ledger:gql:mutation:retryInvoice');

it('answers not_allowed when the invoice is not failed on retryInvoice', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $invoice = gqlMakeInvoice($organization, ['status' => InvoiceStatus::Finalized->value]);

    $response = gqlPost(
        'mutation($input: RetryInvoiceInput!) { retryInvoice(input: $input) { id } }',
        ['input' => ['id' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions.code'))->toBe('invalid_status');
})->group('ledger:gql:mutation:retryInvoice');

it('downloads the invoice PDF through downloadInvoice', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    // LAGO_DISABLE_PDF_GENERATION short-circuits the Gotenberg call, exactly
    // like Rails — the mutation answers the invoice.
    config(['lago.disable_pdf_generation' => true]);

    $invoice = gqlMakeInvoice($organization, ['status' => InvoiceStatus::Finalized->value]);

    $response = gqlPost(
        'mutation($input: DownloadInvoiceInput!) { downloadInvoice(input: $input) { id status fileUrl } }',
        ['input' => ['id' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.downloadInvoice.id'))->toBe($invoice->id)
        ->and($response->json('data.downloadInvoice.status'))->toBe('finalized')
        ->and($response->json('data.downloadInvoice.fileUrl'))->toBeNull();

    config(['lago.disable_pdf_generation' => false]);
})->group('ledger:gql:mutation:downloadInvoice');

it('answers the is_draft not_allowed envelope when downloading a draft invoice', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $invoice = gqlMakeInvoice($organization, ['status' => InvoiceStatus::Draft->value]);

    $response = gqlPost(
        'mutation($input: DownloadInvoiceInput!) { downloadInvoice(input: $input) { id } }',
        ['input' => ['id' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions.code'))->toBe('is_draft');
})->group('ledger:gql:mutation:downloadInvoice');

it('answers the not_found envelope for an invisible invoice on downloadInvoice', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $invoice = gqlMakeInvoice($organization, ['status' => InvoiceStatus::Closed->value]);

    $response = gqlPost(
        'mutation($input: DownloadInvoiceInput!) { downloadInvoice(input: $input) { id } }',
        ['input' => ['id' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions'))->toBe([
        'status' => 404,
        'code' => 'not_found',
        'details' => ['invoice' => ['not_found']],
    ]);
})->group('ledger:gql:mutation:downloadInvoice');
