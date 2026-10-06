<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Enums\WebhookStatus;
use App\Models\Webhook;
use App\Models\WebhookEndpoint;

/**
 * Ports of Rails' spec/graphql/mutations/webhook_endpoints/, the retry
 * mutation and the webhook(s)/webhook_endpoint(s) resolvers.
 *
 * Ledger rows: gql:query:{webhook,webhooks,webhookEndpoint,webhookEndpoints},
 * gql:mutation:{createWebhookEndpoint,updateWebhookEndpoint,
 * destroyWebhookEndpoint,retryWebhook}.
 */
function gqlWebhooksSetup(): array
{
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser('webhooks@example.com');
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

it('creates, reads, updates and destroys a webhook endpoint', function (): void {
    [$organization, $user] = gqlWebhooksSetup();

    $response = gqlPost(<<<'GQL'
    mutation($input: WebhookEndpointCreateInput!) {
        createWebhookEndpoint(input: $input) { id name webhookUrl signatureAlgo }
    }
    GQL, ['input' => [
        'name' => 'Receiver',
        'webhookUrl' => 'https://foo.bar/foo',
        'signatureAlgo' => 'hmac',
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.createWebhookEndpoint');

    expect($payload['name'])->toBe('Receiver')
        ->and($payload['webhookUrl'])->toBe('https://foo.bar/foo')
        ->and($payload['signatureAlgo'])->toBe('hmac');

    $endpoint = WebhookEndpoint::query()->firstOrFail();

    // Single endpoint fetch.
    $response = gqlPost(<<<'GQL'
    query($id: ID!) {
        webhookEndpoint(id: $id) { id webhookUrl }
    }
    GQL, ['id' => $endpoint->id], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.webhookEndpoint.id'))->toBe($endpoint->id);

    // Collection fetch.
    $response = gqlPost(<<<'GQL'
    query {
        webhookEndpoints(limit: 10) { collection { id webhookUrl } metadata { totalCount } }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.webhookEndpoints.metadata.totalCount'))->toBe(1);

    // Update.
    $response = gqlPost(<<<'GQL'
    mutation($input: WebhookEndpointUpdateInput!) {
        updateWebhookEndpoint(input: $input) { id name }
    }
    GQL, ['input' => ['id' => $endpoint->id, 'name' => 'Renamed', 'webhookUrl' => 'https://foo.bar/foo']],
        gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.updateWebhookEndpoint.name'))->toBe('Renamed');

    // Destroy — the payload carries the deleted id.
    $response = gqlPost(<<<'GQL'
    mutation($input: DestroyWebhookEndpointInput!) {
        destroyWebhookEndpoint(input: $input) { id }
    }
    GQL, ['input' => ['id' => $endpoint->id]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.destroyWebhookEndpoint.id'))->toBe($endpoint->id)
        ->and(WebhookEndpoint::count())->toBe(0);
})->group('ledger:gql:mutation:createWebhookEndpoint', 'ledger:gql:query:webhookEndpoint', 'ledger:gql:query:webhookEndpoints', 'ledger:gql:mutation:updateWebhookEndpoint', 'ledger:gql:mutation:destroyWebhookEndpoint');

it('answers not_found for an unknown webhook endpoint', function (): void {
    [$organization, $user] = gqlWebhooksSetup();

    $response = gqlPost(<<<'GQL'
    query {
        webhookEndpoint(id: "00000000-0000-0000-0000-000000000000") { id }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('not_found')
        ->and($response->json('errors.0.extensions.details.webhookEndpoint.0'))->toBe('not_found');
})->group('ledger:gql:query:webhookEndpoint');

it('lists the webhooks of an endpoint with the filters', function (): void {
    [$organization, $user] = gqlWebhooksSetup();

    $endpoint = WebhookEndpoint::factory()->create(['organization_id' => $organization->id]);

    Webhook::factory()->create([
        'organization_id' => $organization->id,
        'webhook_endpoint_id' => $endpoint->id,
        'webhook_type' => 'invoice.created',
        'object_type' => 'invoice',
        'status' => WebhookStatus::Succeeded->value,
        'http_status' => 200,
        'payload' => ['invoice' => ['lago_id' => 'x']],
    ]);
    Webhook::factory()->create([
        'organization_id' => $organization->id,
        'webhook_endpoint_id' => $endpoint->id,
        'webhook_type' => 'customer.created',
        'object_type' => 'customer',
        'status' => WebhookStatus::Failed->value,
        'http_status' => null,
    ]);

    $response = gqlPost(<<<'GQL'
    query($endpointId: String!) {
        webhooks(webhookEndpointId: $endpointId, limit: 10) {
            collection { id webhookType objectType status httpStatus payload endpoint }
            metadata { totalCount }
        }
    }
    GQL, ['endpointId' => $endpoint->id], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.webhooks.metadata.totalCount'))->toBe(2);

    // The statuses filter narrows to the succeeded row; the enum name and
    // the JSON-encoded payload ride the wire like Rails.
    $response = gqlPost(<<<'GQL'
    query($endpointId: String!) {
        webhooks(webhookEndpointId: $endpointId, statuses: [succeeded], limit: 10) {
            collection { webhookType status payload httpStatus }
        }
    }
    GQL, ['endpointId' => $endpoint->id], gqlAuthHeaders($user, $organization->id));

    $row = $response->json('data.webhooks.collection.0');

    expect($row['webhookType'])->toBe('invoice.created')
        ->and($row['status'])->toBe('succeeded')
        ->and($row['httpStatus'])->toBe(200)
        ->and($row['payload'])->toBeJson();

    // The eventTypes filter.
    $response = gqlPost(<<<'GQL'
    query($endpointId: String!) {
        webhooks(webhookEndpointId: $endpointId, eventTypes: ["customer.created"], limit: 10) {
            collection { webhookType }
        }
    }
    GQL, ['endpointId' => $endpoint->id], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.webhooks.collection.0.webhookType'))->toBe('customer.created');

    // A single webhook by id; unknown ids answer not_found.
    $webhook = Webhook::query()->firstOrFail();

    $response = gqlPost(<<<'GQL'
    query($id: ID!) {
        webhook(id: $id) { id webhookType }
    }
    GQL, ['id' => $webhook->id], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.webhook.id'))->toBe($webhook->id);

    $response = gqlPost(<<<'GQL'
    query {
        webhook(id: "00000000-0000-0000-0000-000000000000") { id }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('not_found');
})->group('ledger:gql:query:webhooks', 'ledger:gql:query:webhook');

it('retries a webhook', function (): void {
    Queue::fake();

    [$organization, $user] = gqlWebhooksSetup();

    $endpoint = WebhookEndpoint::factory()->create(['organization_id' => $organization->id]);
    $webhook = Webhook::factory()->create([
        'organization_id' => $organization->id,
        'webhook_endpoint_id' => $endpoint->id,
        'webhook_type' => 'invoice.created',
        'object_type' => 'invoice',
        'status' => WebhookStatus::Failed->value,
    ]);

    $response = gqlPost(<<<'GQL'
    mutation($input: RetryWebhookInput!) {
        retryWebhook(input: $input) { id status }
    }
    GQL, ['input' => ['id' => $webhook->id]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.retryWebhook.id'))->toBe($webhook->id);

    // The service enqueues the HTTP job — the status flip happens when the
    // job runs (Rails: SendHttpJob.perform_later).
    Queue::assertPushed(\App\Jobs\SendHttpWebhookJob::class);
})->group('ledger:gql:mutation:retryWebhook');
