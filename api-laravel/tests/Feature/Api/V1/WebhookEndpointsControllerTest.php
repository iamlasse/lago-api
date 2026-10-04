<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:POST:/api/v1/webhook_endpoints',
    'ledger:rest:GET:/api/v1/webhook_endpoints',
    'ledger:rest:GET:/api/v1/webhook_endpoints/:id',
    'ledger:rest:PUT:/api/v1/webhook_endpoints/:id',
    'ledger:rest:PATCH:/api/v1/webhook_endpoints/:id',
    'ledger:rest:PATCH:/api/v2/webhook_endpoints/:id',
    'ledger:rest:DELETE:/api/v1/webhook_endpoints/:id',
);

use App\Models\Organization;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' spec/requests/api/v1/webhook_endpoints_controller_spec.rb.
 *
 * Rails' spec_helper.rb sets ENV["LAGO_WEBHOOK_ALLOW_PRIVATE_URLS"] ||=
 * "true" — the SSRF AddressGuard is disabled suite-wide — so every
 * webhookEndpointScenario runs with the same override (the dedicated
 * private-address test re-enables the guard explicitly).
 */
beforeEach(function (): void {
    $_ENV['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'] = 'true';
    $_SERVER['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'] = 'true';
});

afterEach(function (): void {
    unset($_ENV['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'], $_SERVER['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS']);
});

function webhookOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create($attributes);

    return [$organization, $organization->apiKeys()->first()];
}

/**
 * Rails' :premium spec tag — License.premium? is true while a license key
 * is configured.
 */
function withWebhookPremiumLicense(callable $scenario): void
{
    putenv('LAGO_LICENSE=premium-license-token');
    $_ENV['LAGO_LICENSE'] = 'premium-license-token';

    try {
        $scenario();
    } finally {
        putenv('LAGO_LICENSE');
        unset($_ENV['LAGO_LICENSE']);
    }
}

// -- POST /api/v1/webhook_endpoints ---------------------------------------------

it('creates a webhook endpoint', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    $createParams = [
        'webhook_url' => 'https://example.com/hook',
        'signature_algo' => 'jwt',
        'name' => 'Test Webhook',
        'event_types' => ['customer.created', 'customer.updated'],
    ];

    $this->postJson('/api/v1/webhook_endpoints', ['webhook_endpoint' => $createParams], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($organization): void {
        $json->where('webhook_endpoint.webhook_url', 'https://example.com/hook')
            ->where('webhook_endpoint.signature_algo', 'jwt')
            ->where('webhook_endpoint.name', 'Test Webhook')
            ->where('webhook_endpoint.event_types', ['customer.created', 'customer.updated'])
            ->where('webhook_endpoint.lago_organization_id', $organization->id)
            ->has('webhook_endpoint.lago_id')
            ->has('webhook_endpoint.created_at')
            ->etc();
    });
});

it('rejects a non-array event_types with must_be_array', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    $this->postJson('/api/v1/webhook_endpoints', ['webhook_endpoint' => [
        'webhook_url' => 'https://example.com/hook',
        'event_types' => 'wrong',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertExactJson([
            'status' => 422,
            'error' => 'Unprocessable Entity',
            'code' => 'validation_errors',
            'error_details' => ['event_types' => ['must_be_array']],
        ]);
});

it('rejects event_types containing invalid types', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    $this->postJson('/api/v1/webhook_endpoints', ['webhook_endpoint' => [
        'webhook_url' => 'https://example.com/hook',
        'event_types' => ['wrong.type'],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertExactJson([
            'status' => 422,
            'error' => 'Unprocessable Entity',
            'code' => 'validation_errors',
            'error_details' => ['event_types' => ['contains invalid types: ["wrong.type"]']],
        ]);
});

it('accepts the [*] event_types wildcard', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    $this->postJson('/api/v1/webhook_endpoints', ['webhook_endpoint' => [
        'webhook_url' => 'https://example.com/hook',
        'event_types' => ['*'],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('webhook_endpoint.webhook_url', 'https://example.com/hook')
        ->assertJsonPath('webhook_endpoint.event_types', null);
});

it('rejects a webhook endpoint that exceeds the per-organization limit', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    WebhookEndpoint::factory()->count(WebhookEndpoint::LIMIT)->sequence(fn ($sequence): array => [
        'webhook_url' => 'https://example.com/hooks/'.$sequence->index,
    ])->create([
        'organization_id' => $organization->id,
    ]);

    $this->postJson('/api/v1/webhook_endpoints', ['webhook_endpoint' => [
        'webhook_url' => 'https://example.com/hook',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertExactJson([
            'status' => 422,
            'error' => 'Unprocessable Entity',
            'code' => 'validation_errors',
            // The :exceeded_limit symbol has no i18n entry — Rails renders
            // the humanized fallback message.
            'error_details' => ['webhook_url' => ['Exceeded limit']],
        ]);
});

it('rejects a private webhook url while the address guard is enabled', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    unset($_ENV['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'], $_SERVER['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS']);

    $this->postJson('/api/v1/webhook_endpoints', ['webhook_endpoint' => [
        'webhook_url' => 'http://localhost/hook',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertExactJson([
            'status' => 422,
            'error' => 'Unprocessable Entity',
            'code' => 'validation_errors',
            'error_details' => ['webhook_url' => ['url_is_invalid']],
        ]);
});

// -- GET /api/v1/webhook_endpoints ------------------------------------------------

it('returns all webhook endpoints from the organization', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    WebhookEndpoint::factory()->count(2)->create(['organization_id' => $organization->id]);

    $this->getJson('/api/v1/webhook_endpoints', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->count('webhook_endpoints', 3)
                ->where('meta.total_count', 3)
                ->etc();
        });
});

// -- GET /api/v1/webhook_endpoints/:id ----------------------------------------------

it('returns a webhook endpoint', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    $webhookEndpoint = WebhookEndpoint::factory()->create(['organization_id' => $organization->id]);

    $this->getJson('/api/v1/webhook_endpoints/'.$webhookEndpoint->id, [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonPath('webhook_endpoint.lago_id', $webhookEndpoint->id);
});

it('returns not_found when the webhook endpoint does not exist', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    $this->getJson('/api/v1/webhook_endpoints/'.Illuminate\Support\Str::uuid(), [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound()->assertExactJson([
        'status' => 404,
        'error' => 'Not Found',
        'code' => 'webhook_endpoint_not_found',
    ]);
});

// -- DELETE /api/v1/webhook_endpoints/:id ---------------------------------------------

it('deletes a webhook endpoint', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    $webhookEndpoint = WebhookEndpoint::factory()->create(['organization_id' => $organization->id]);

    $this->deleteJson('/api/v1/webhook_endpoints/'.$webhookEndpoint->id, headers: [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk();

    // The organization factory's own default endpoint remains.
    expect(WebhookEndpoint::query()->where('id', $webhookEndpoint->id)->doesntExist())->toBeTrue();
});

it('returns the deleted webhook endpoint', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    $webhookEndpoint = WebhookEndpoint::factory()->eventTypes(['customer.created'])
        ->create(['organization_id' => $organization->id]);

    $this->deleteJson('/api/v1/webhook_endpoints/'.$webhookEndpoint->id, headers: [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($webhookEndpoint): void {
        $json->where('webhook_endpoint.lago_id', $webhookEndpoint->id)
            ->where('webhook_endpoint.webhook_url', $webhookEndpoint->webhook_url)
            ->where('webhook_endpoint.name', $webhookEndpoint->name)
            ->where('webhook_endpoint.event_types', ['customer.created'])
            ->etc();
    });
});

it('returns not_found when deleting a webhook endpoint that does not exist', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    $this->deleteJson('/api/v1/webhook_endpoints/'.Illuminate\Support\Str::uuid(), headers: [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound();
});

// -- PUT /api/v1/webhook_endpoints/:id -------------------------------------------------

it('updates a webhook endpoint', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    $webhookEndpoint = WebhookEndpoint::factory()->create(['organization_id' => $organization->id]);

    $this->putJson('/api/v1/webhook_endpoints/'.$webhookEndpoint->id, ['webhook_endpoint' => [
        'webhook_url' => 'http://foo.bar',
        'signature_algo' => 'hmac',
        'name' => 'Updated Webhook',
        'event_types' => ['invoice.created', 'invoice.voided'],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->where('webhook_endpoint.webhook_url', 'http://foo.bar')
                ->where('webhook_endpoint.signature_algo', 'hmac')
                ->where('webhook_endpoint.name', 'Updated Webhook')
                ->where('webhook_endpoint.event_types', ['invoice.created', 'invoice.voided'])
                ->etc();
        });
});

it('updates a webhook endpoint with event_types explicitly set to null', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    $webhookEndpoint = WebhookEndpoint::factory()->eventTypes(['customer.created'])
        ->create(['organization_id' => $organization->id]);

    $this->putJson('/api/v1/webhook_endpoints/'.$webhookEndpoint->id, ['webhook_endpoint' => [
        'event_types' => null,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('webhook_endpoint.event_types', null);
});

it('updates a webhook endpoint with event_types explicitly set to an empty array', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    $webhookEndpoint = WebhookEndpoint::factory()->eventTypes(['customer.created'])
        ->create(['organization_id' => $organization->id]);

    $this->putJson('/api/v1/webhook_endpoints/'.$webhookEndpoint->id, ['webhook_endpoint' => [
        'event_types' => [],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('webhook_endpoint.event_types', []);
});

it('rejects a non-array event_types on update with must_be_array', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    $webhookEndpoint = WebhookEndpoint::factory()->create(['organization_id' => $organization->id]);

    $this->putJson('/api/v1/webhook_endpoints/'.$webhookEndpoint->id, ['webhook_endpoint' => [
        'event_types' => 'wrong',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertExactJson([
            'status' => 422,
            'error' => 'Unprocessable Entity',
            'code' => 'validation_errors',
            'error_details' => ['event_types' => ['must_be_array']],
        ]);
});

it('rejects event_types containing invalid types on update', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    $webhookEndpoint = WebhookEndpoint::factory()->create(['organization_id' => $organization->id]);

    $this->putJson('/api/v1/webhook_endpoints/'.$webhookEndpoint->id, ['webhook_endpoint' => [
        'event_types' => ['wrong.type'],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertExactJson([
            'status' => 422,
            'error' => 'Unprocessable Entity',
            'code' => 'validation_errors',
            'error_details' => ['event_types' => ['contains invalid types: ["wrong.type"]']],
        ]);
});

it('accepts the [*] event_types wildcard on update', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    $webhookEndpoint = WebhookEndpoint::factory()->create(['organization_id' => $organization->id]);

    $this->putJson('/api/v1/webhook_endpoints/'.$webhookEndpoint->id, ['webhook_endpoint' => [
        'event_types' => ['*'],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('webhook_endpoint.event_types', null);
});

it('updates webhook_url without resetting signature_algo', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    // The signature_algo column default is jwt (the factory's string-name
    // state bypasses the model cast, so the default is used directly).
    $webhookEndpoint = WebhookEndpoint::factory()->create(['organization_id' => $organization->id]);

    $this->putJson('/api/v1/webhook_endpoints/'.$webhookEndpoint->id, ['webhook_endpoint' => [
        'webhook_url' => 'http://foo.bar',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('webhook_endpoint.webhook_url', 'http://foo.bar')
        ->assertJsonPath('webhook_endpoint.signature_algo', 'jwt');
});

it('returns not_found when updating a webhook endpoint that does not exist', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    $this->putJson('/api/v1/webhook_endpoints/'.Illuminate\Support\Str::uuid(), ['webhook_endpoint' => [
        'webhook_url' => 'http://foo.bar',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- PATCH /api/v1/webhook_endpoints/:id ------------------------------------------------
// Rails routes PATCH and PUT to the same WebhookEndpointsController#update
// (resources :webhook_endpoints draws both verbs; no PATCH-specific branch
// exists), so the scenarios below port the PUT section's expectations to the
// PATCH verb.

it('updates a webhook endpoint via PATCH', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    $webhookEndpoint = WebhookEndpoint::factory()->create(['organization_id' => $organization->id]);

    $this->patchJson('/api/v1/webhook_endpoints/'.$webhookEndpoint->id, ['webhook_endpoint' => [
        'webhook_url' => 'http://foo.bar',
        'signature_algo' => 'hmac',
        'name' => 'Updated Webhook',
        'event_types' => ['invoice.created', 'invoice.voided'],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->where('webhook_endpoint.webhook_url', 'http://foo.bar')
                ->where('webhook_endpoint.signature_algo', 'hmac')
                ->where('webhook_endpoint.name', 'Updated Webhook')
                ->where('webhook_endpoint.event_types', ['invoice.created', 'invoice.voided'])
                ->etc();
        });
});

it('updates a webhook endpoint with event_types explicitly set to null via PATCH', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    $webhookEndpoint = WebhookEndpoint::factory()->eventTypes(['customer.created'])
        ->create(['organization_id' => $organization->id]);

    $this->patchJson('/api/v1/webhook_endpoints/'.$webhookEndpoint->id, ['webhook_endpoint' => [
        'event_types' => null,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('webhook_endpoint.event_types', null);
});

// -- v2 mirror ---------------------------------------------------------------------------

it('mirrors the webhook endpoint endpoints at v2 with the beta header', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    $this->postJson('/api/v2/webhook_endpoints', ['webhook_endpoint' => [
        'webhook_url' => 'https://example.com/v2',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('webhook_endpoint.webhook_url', 'https://example.com/v2');

    $this->getJson('/api/v2/webhook_endpoints', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        // The created endpoint plus the organization factory's default one.
        ->assertJsonPath('meta.total_count', 2);

    $this->getJson('/api/v2/webhook_endpoints/'.Illuminate\Support\Str::uuid(), [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound()->assertHeader('X-Lago-Endpoint-Status', 'beta');
});

it('mirrors the webhook endpoint update via PATCH at v2 with the beta header', function (): void {
    [$organization, $apiKey] = webhookOrganization();

    $webhookEndpoint = WebhookEndpoint::factory()->create(['organization_id' => $organization->id]);

    $this->patchJson('/api/v2/webhook_endpoints/'.$webhookEndpoint->id, ['webhook_endpoint' => [
        'webhook_url' => 'https://example.com/v2-patch',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('webhook_endpoint.lago_id', $webhookEndpoint->id)
        ->assertJsonPath('webhook_endpoint.webhook_url', 'https://example.com/v2-patch');
});

// -- api permissions ------------------------------------------------------------------------

it('requires an api permission to write webhook endpoints', function (): void {
    withWebhookPremiumLicense(function (): void {
        config(['lago.license' => 'premium-license-token']);

        [$organization, $apiKey] = webhookOrganization();

        DB::update(
            'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
            ['api_permissions', $organization->id],
        );
        DB::update(
            'update api_keys set permissions = ?::jsonb where id = ?',
            [json_encode(['webhook_endpoint' => ['read']]), $apiKey->id],
        );

        $this->postJson('/api/v1/webhook_endpoints', ['webhook_endpoint' => [
            'webhook_url' => 'https://example.com/denied',
        ]], ['Authorization' => 'Bearer '.$apiKey->value])
            ->assertForbidden()
            ->assertExactJson([
                'status' => 403,
                'error' => 'Forbidden',
                'code' => 'write_action_not_allowed_for_webhook_endpoint',
            ]);
    });
});

it('allows the write when the api permission grants it', function (): void {
    withWebhookPremiumLicense(function (): void {
        config(['lago.license' => 'premium-license-token']);

        [$organization, $apiKey] = webhookOrganization();

        DB::update(
            'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
            ['api_permissions', $organization->id],
        );
        DB::update(
            'update api_keys set permissions = ?::jsonb where id = ?',
            [json_encode(['webhook_endpoint' => ['write']]), $apiKey->id],
        );

        $this->postJson('/api/v1/webhook_endpoints', ['webhook_endpoint' => [
            'webhook_url' => 'https://example.com/allowed',
        ]], ['Authorization' => 'Bearer '.$apiKey->value])
            ->assertOk()
            ->assertJsonPath('webhook_endpoint.webhook_url', 'https://example.com/allowed');
    });
});
