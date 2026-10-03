<?php

declare(strict_types=1);

require_once __DIR__.'/../GraphQLHelpers.php';
require_once __DIR__.'/../AuthPlumbingTest.php';
require_once __DIR__.'/../Resolvers/InvoicesResolverTest.php';

use App\Models\Invoice;
use App\Enums\InvoiceStatus;

/**
 * Ports of Rails' spec/graphql/mutations/invoices/{finalize,...}_spec.rb
 * (the scenarios this slice ports — the create/update/delete/void/download/
 * retry/refresh mutations stay on the null stub until their services ship
 * with the one-off invoice, credit-notes and payments slices) over the
 * frozen SDL.
 *
 * Ledger rows: gql:mutation:finalizeInvoice.
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

it('leaves the other invoice mutations on the null stub until their services land', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $invoice = gqlMakeInvoice($organization);

    $mutate = static fn (string $mutation): mixed => gqlPost(
        $mutation,
        ['input' => ['id' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    )->json('data');

    expect($mutate('mutation($input: DeleteInvoiceInput!) { deleteInvoice(input: $input) { id } }'))->toBe(['deleteInvoice' => null])
        ->and($mutate('mutation($input: VoidInvoiceInput!) { voidInvoice(input: $input) { id } }'))->toBe(['voidInvoice' => null])
        ->and($mutate('mutation($input: RefreshInvoiceInput!) { refreshInvoice(input: $input) { id } }'))->toBe(['refreshInvoice' => null])
        ->and($mutate('mutation($input: RetryInvoiceInput!) { retryInvoice(input: $input) { id } }'))->toBe(['retryInvoice' => null])
        ->and($mutate('mutation($input: DownloadInvoiceInput!) { downloadInvoice(input: $input) { id } }'))->toBe(['downloadInvoice' => null]);
})->group('ledger:gql:mutation:finalizeInvoice');
