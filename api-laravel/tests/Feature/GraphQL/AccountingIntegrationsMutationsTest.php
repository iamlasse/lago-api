<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use Illuminate\Support\Facades\Queue;
use App\Models\Integrations\XeroIntegration;
use App\Models\Integrations\NetsuiteIntegration;

/**
 * Ports of Rails' spec/graphql/mutations/integrations/{xero,netsuite}/ —
 * the accounting-integration CRUD surface over POST /graphql, against the
 * frozen-schema contract.
 */
function gqlAccountingSetup(string $integration, string $email): array
{
    config()->set('lago.license', 'premium-token');

    $organization = gqlCreateOrganization('Accounting Org');
    App\Models\BillingEntity::factory()->create(['organization_id' => $organization->id]);
    $organization->premium_integrations = [$integration];
    $organization->save();

    $user = gqlCreateUser($email);
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

it('creates a xero integration', function (): void {
    Queue::fake();

    [$organization, $user] = gqlAccountingSetup('xero', 'xero@example.com');

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateXeroIntegrationInput!) {
        createXeroIntegration(input: $input) {
            id
            code
            name
            connectionId
            syncInvoices
            syncCreditNotes
            syncPayments
        }
    }
    GQL, ['input' => [
        'code' => 'xero1',
        'name' => 'Xero 1',
        'connectionId' => 'this-is-random-uuid',
        'syncInvoices' => true,
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.createXeroIntegration');

    expect($payload['id'])->not->toBeNull()
        ->and($payload['code'])->toBe('xero1')
        ->and($payload['name'])->toBe('Xero 1')
        ->and($payload['connectionId'])->toBe('this-is-random-uuid')
        ->and($payload['syncInvoices'])->toBeTrue()
        ->and($payload['syncPayments'])->toBeFalse();

    $integration = XeroIntegration::query()->firstOrFail();

    expect($integration->connectionId())->toBe('this-is-random-uuid');

    Queue::assertPushed(App\Jobs\Integrations\Aggregator\PerformSyncJob::class);
});

it('refuses a xero integration without the premium flag', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = gqlCreateOrganization('Plain Org');
    App\Models\BillingEntity::factory()->create(['organization_id' => $organization->id]);
    $user = gqlCreateUser('xero-plain@example.com');
    gqlCreateMembership($user, $organization);

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateXeroIntegrationInput!) {
        createXeroIntegration(input: $input) {
            id
        }
    }
    GQL, ['input' => [
        'code' => 'xero1',
        'name' => 'Xero 1',
        'connectionId' => 'conn1',
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.createXeroIntegration'))->toBeNull()
        ->and(XeroIntegration::query()->count())->toBe(0);
});

it('updates a xero integration', function (): void {
    [$organization, $user] = gqlAccountingSetup('xero', 'xero-update@example.com');

    $integration = XeroIntegration::factory()->forOrganization($organization)->create();

    $response = gqlPost(<<<'GQL'
    mutation($input: UpdateXeroIntegrationInput!) {
        updateXeroIntegration(input: $input) {
            id
            name
            code
            syncInvoices
        }
    }
    GQL, ['input' => [
        'id' => $integration->id,
        'name' => 'Xero EU',
        'code' => 'xero_eu',
        'syncInvoices' => false,
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.updateXeroIntegration');

    expect($payload['id'])->toBe($integration->id)
        ->and($payload['name'])->toBe('Xero EU')
        ->and($payload['code'])->toBe('xero_eu')
        ->and($payload['syncInvoices'])->toBeFalse();
});

it('creates a netsuite integration and obfuscates the secrets', function (): void {
    Queue::fake();

    [$organization, $user] = gqlAccountingSetup('netsuite', 'netsuite@example.com');

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateNetsuiteIntegrationInput!) {
        createNetsuiteIntegration(input: $input) {
            id
            code
            name
            accountId
            clientId
            clientSecret
            tokenId
            tokenSecret
            scriptEndpointUrl
            connectionId
        }
    }
    GQL, ['input' => [
        'code' => 'netsuite1',
        'name' => 'Netsuite 1',
        'connectionId' => 'conn1',
        'clientId' => 'cl1',
        'clientSecret' => 'secret-key',
        'tokenId' => 'xyz',
        'tokenSecret' => 'zyx',
        'accountId' => 'ACC 1',
        'scriptEndpointUrl' => 'https://restlets.example.com/script',
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.createNetsuiteIntegration');

    expect($payload['code'])->toBe('netsuite1')
        ->and($payload['accountId'])->toBe('acc-1')
        ->and($payload['clientId'])->toBe('cl1')
        ->and($payload['clientSecret'])->toBe('••••••••…key')
        ->and($payload['tokenSecret'])->toBe('••••••••…zyx')
        ->and($payload['tokenId'])->toBe('xyz')
        ->and($payload['scriptEndpointUrl'])->toBe('https://restlets.example.com/script');

    $integration = NetsuiteIntegration::query()->firstOrFail();

    expect($integration->connectionId())->toBe('conn1')
        ->and($integration->tokenSecret())->toBe('zyx');

    Queue::assertPushed(App\Jobs\Integrations\Aggregator\SendRestletEndpointJob::class);
    Queue::assertPushed(App\Jobs\Integrations\Aggregator\PerformSyncJob::class);
});

it('refuses a netsuite integration without the premium flag', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = gqlCreateOrganization('Plain NS Org');
    App\Models\BillingEntity::factory()->create(['organization_id' => $organization->id]);
    $user = gqlCreateUser('netsuite-plain@example.com');
    gqlCreateMembership($user, $organization);

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateNetsuiteIntegrationInput!) {
        createNetsuiteIntegration(input: $input) {
            id
        }
    }
    GQL, ['input' => [
        'code' => 'netsuite1',
        'name' => 'Netsuite 1',
        'connectionId' => 'conn1',
        'clientId' => 'cl1',
        'clientSecret' => 'secret',
        'tokenId' => 'xyz',
        'tokenSecret' => 'zyx',
        'accountId' => 'acc1',
        'scriptEndpointUrl' => 'https://restlets.example.com/script',
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.createNetsuiteIntegration'))->toBeNull()
        ->and(NetsuiteIntegration::query()->count())->toBe(0);
});

it('updates a netsuite integration', function (): void {
    Queue::fake();

    [$organization, $user] = gqlAccountingSetup('netsuite', 'netsuite-update@example.com');

    $integration = NetsuiteIntegration::factory()->forOrganization($organization)->create();

    $response = gqlPost(<<<'GQL'
    mutation($input: UpdateNetsuiteIntegrationInput!) {
        updateNetsuiteIntegration(input: $input) {
            id
            name
            code
            scriptEndpointUrl
            syncInvoices
        }
    }
    GQL, ['input' => [
        'id' => $integration->id,
        'name' => 'Netsuite 1',
        'code' => 'netsuite1',
        'scriptEndpointUrl' => 'https://restlets.example.com/new-script',
        'syncInvoices' => false,
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.updateNetsuiteIntegration');

    expect($payload['id'])->toBe($integration->id)
        ->and($payload['name'])->toBe('Netsuite 1')
        ->and($payload['code'])->toBe('netsuite1')
        ->and($payload['scriptEndpointUrl'])->toBe('https://restlets.example.com/new-script')
        ->and($payload['syncInvoices'])->toBeFalse();

    Queue::assertPushed(App\Jobs\Integrations\Aggregator\SendRestletEndpointJob::class);
});

it('destroys an accounting integration', function (): void {
    [$organization, $user] = gqlAccountingSetup('xero', 'xero-destroy@example.com');

    $integration = XeroIntegration::factory()->forOrganization($organization)->create();

    $response = gqlPost(<<<'GQL'
    mutation($input: DestroyIntegrationInput!) {
        destroyIntegration(input: $input) {
            id
        }
    }
    GQL, ['input' => ['id' => $integration->id]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.destroyIntegration');

    expect($payload['id'])->toBe($integration->id)
        ->and(XeroIntegration::query()->count())->toBe(0);
});
