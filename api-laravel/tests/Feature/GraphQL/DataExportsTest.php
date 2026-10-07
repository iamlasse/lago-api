<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';

use App\Models\User;
use App\Support\Utils\AuthToken;
use Illuminate\Support\Facades\Queue;
use App\Jobs\DataExports\ExportResourcesJob;

/**
 * Ports of Rails' spec/graphql/mutations/data_exports/{invoices,
 * credit_notes}/create_spec.rb over the frozen SDL.
 *
 * TODO(port): Rails requires the "invoices:export" / "credit_notes:export"
 * permissions; the permission port is pending (graphql/FULL_SCHEMA_NOTES.md
 * item 3).
 */
const CREATE_INVOICES_EXPORT_MUTATION = <<<'GQL'
mutation($input: CreateDataExportsInvoicesInput!) {
    createInvoicesDataExport(input: $input) {
        id
        status
    }
}
GQL;

const CREATE_CREDIT_NOTES_EXPORT_MUTATION = <<<'GQL'
mutation($input: CreateDataExportsCreditNotesInput!) {
    createCreditNotesDataExport(input: $input) {
        id
        status
    }
}
GQL;

function gqlExportHeaders(User $user, string $organizationId): array
{
    return [
        'Authorization' => 'Bearer '.AuthToken::encode($user, extra: ['login_method' => 'email_password']),
        'x-lago-organization' => $organizationId,
    ];
}

function exportMembership(): array
{
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    return [$organization, $user];
}

it('creates an invoices data export', function (): void {
    Queue::fake();

    [$organization, $user] = exportMembership();

    $response = gqlPost(CREATE_INVOICES_EXPORT_MUTATION, [
        'input' => [
            'format' => 'csv',
            'resourceType' => 'invoices',
            'filters' => ['searchTerm' => 'INV', 'paymentStatus' => ['pending']],
        ],
    ], gqlExportHeaders($user, $organization->id));

    $response->assertOk();

    $payload = $response->json('data.createInvoicesDataExport');

    expect($payload['status'])->toBe('pending');

    $export = App\Models\DataExport::query()->find($payload['id']);

    expect($export->resource_type)->toBe('invoices')
        ->and($export->resource_query['search_term'])->toBe('INV')
        ->and($export->resource_query['payment_status'])->toBe(['pending'])
        ->and($export->membership->user_id)->toBe($user->id);

    Queue::assertPushed(ExportResourcesJob::class);
});

it('creates a credit notes data export', function (): void {
    Queue::fake();

    [$organization, $user] = exportMembership();

    $response = gqlPost(CREATE_CREDIT_NOTES_EXPORT_MUTATION, [
        'input' => [
            'format' => 'csv',
            'resourceType' => 'credit_note_items',
            'filters' => ['creditStatus' => ['consumed']],
        ],
    ], gqlExportHeaders($user, $organization->id));

    $response->assertOk();

    $payload = $response->json('data.createCreditNotesDataExport');

    $export = App\Models\DataExport::query()->find($payload['id']);

    expect($payload['status'])->toBe('pending')
        ->and($export->resource_type)->toBe('credit_note_items')
        ->and($export->resource_query['credit_status'])->toBe(['consumed']);
});

it('requires a current user', function (): void {
    [$organization, $user] = exportMembership();

    $response = gqlPost(CREATE_INVOICES_EXPORT_MUTATION, [
        'input' => ['format' => 'csv', 'resourceType' => 'invoices', 'filters' => []],
    ], ['x-lago-organization' => $organization->id]);

    expect($response->json('errors.0.extensions.code'))->toBe('unauthorized');
});

it('requires a current organization', function (): void {
    [$organization, $user] = exportMembership();

    $response = gqlPost(CREATE_INVOICES_EXPORT_MUTATION, [
        'input' => ['format' => 'csv', 'resourceType' => 'invoices', 'filters' => []],
    ], ['Authorization' => 'Bearer '.AuthToken::encode($user, extra: ['login_method' => 'email_password'])]);

    expect($response->json('errors.0.extensions.code'))->toBe('forbidden');
});
