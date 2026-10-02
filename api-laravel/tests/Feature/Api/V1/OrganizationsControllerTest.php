<?php

declare(strict_types=1);

uses()->group('ledger:rest:GET:/api/v1/organizations', 'ledger:rest:PUT:/api/v1/organizations', 'ledger:rest:GET:/api/v1/organizations/grpc_token');

use App\Models\Organization;

/**
 * Port of Rails' spec/requests/api/v1/organizations_controller_spec.rb.
 */
function organizationWithApiKey(array $attributes = []): array
{
    $organization = Organization::factory()->create($attributes);

    return [$organization, $organization->apiKeys()->first()];
}

/**
 * Rails' :premium spec tag — License.premium? is true while a license key
 * is configured.
 */
function withPremiumLicense(callable $scenario): void
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

it('updates an organization', function (): void {
    [$organization, $apiKey] = organizationWithApiKey();

    $updateParams = [
        'country' => 'pl',
        'default_currency' => 'EUR',
        'address_line1' => 'address1',
        'address_line2' => 'address2',
        'state' => 'state',
        'zipcode' => '10000',
        'email' => 'mail@example.com',
        'city' => 'test_city',
        'legal_name' => 'test1',
        'legal_number' => '123',
        'timezone' => 'Europe/Paris',
        'webhook_url' => $webhookUrl = 'https://webhook.example.com/hook',
        'email_settings' => ['invoice.finalized'],
        'document_number_prefix' => 'ORG-2',
        'finalize_zero_amount_invoice' => false,
        'billing_configuration' => [
            'invoice_footer' => 'footer',
            'invoice_grace_period' => 3,
            'document_locale' => 'fr',
        ],
    ];

    $this->putJson('/api/v1/organizations', ['organization' => $updateParams], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonStructure(['organization']);

    $organization->refresh();

    $this->getJson('/api/v1/organizations', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($organization, $webhookUrl) {
            $json->where('organization.name', $organization->name)
                ->where('organization.default_currency', 'EUR')
                ->where('organization.webhook_url', $webhookUrl)
                ->where('organization.webhook_urls', [$webhookUrl])
                ->where('organization.document_numbering', 'per_customer')
                ->where('organization.document_number_prefix', 'ORG-2')
                ->where('organization.finalize_zero_amount_invoice', false)
                // TODO(:timezone): Timezone update is turned off for now.
                ->where('organization.billing_configuration.invoice_footer', 'footer')
                ->where('organization.billing_configuration.document_locale', 'fr')
                ->has('organization.taxes')
                ->etc();
        });
});

it('updates an organization with premium features', function (): void {
    withPremiumLicense(function (): void {
        [$organization, $apiKey] = organizationWithApiKey();

        $this->putJson('/api/v1/organizations', ['organization' => [
            'timezone' => 'Europe/Paris',
            'email_settings' => ['invoice.finalized'],
            'billing_configuration' => [
                'invoice_grace_period' => 3,
            ],
        ]], ['Authorization' => 'Bearer '.$apiKey->value])
            ->assertOk()
            ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) {
                $json->where('organization.timezone', 'Europe/Paris')
                    ->where('organization.email_settings', ['invoice.finalized'])
                    ->where('organization.billing_configuration.invoice_grace_period', 3)
                    ->etc();
            });
    });
});

it('returns the grpc_token', function (): void {
    [$organization, $apiKey] = organizationWithApiKey();

    // Rails loads the key at boot from config/keys/private.pem or
    // LAGO_RSA_PRIVATE_KEY; the test mints its own key pair.
    openssl_pkey_export(openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]), $pem);

    config(['lago.rsa_private_key' => $pem]);

    $response = $this->getJson('/api/v1/organizations/grpc_token', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk();

    $token = $response->json('organization.grpc_token');

    expect($token)->not->toBeNull();

    // RS256-signed, aud "lago-grpc", organization_id of the current org.
    $parts = explode('.', (string) $token);
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

    expect($payload['organization_id'])->toBe($organization->id)
        ->and($payload['aud'])->toBe('lago-grpc');
});

it('returns the organization', function (): void {
    [$organization, $apiKey] = organizationWithApiKey();

    $this->getJson('/api/v1/organizations', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($organization) {
            $json->where('organization.name', $organization->name)->etc();
        });
});

it('mirrors the organization endpoints at v2 with the beta header', function (): void {
    [$organization, $apiKey] = organizationWithApiKey();

    $this->getJson('/api/v2/organizations', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('organization.lago_id', $organization->id);

    $this->putJson('/api/v2/organizations', ['organization' => [
        'legal_name' => 'V2 Legal',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('organization.legal_name', 'V2 Legal');

    $this->getJson('/api/v2/organizations', ['Authorization' => 'Bearer '.Illuminate\Support\Str::uuid()])
        ->assertUnauthorized()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta');
});

it('requires an api permission to write the organization', function (): void {
    withPremiumLicense(function (): void {
        config(['lago.license' => 'premium-license-token']);

        [$organization, $apiKey] = organizationWithApiKey();

        Illuminate\Support\Facades\DB::update(
            'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
            ['api_permissions', $organization->id],
        );
        Illuminate\Support\Facades\DB::update(
            'update api_keys set permissions = ?::jsonb where id = ?',
            [json_encode(['organization' => ['read']]), $apiKey->id],
        );

        $this->putJson('/api/v1/organizations', ['organization' => [
            'legal_name' => 'Denied',
        ]], ['Authorization' => 'Bearer '.$apiKey->value])
            ->assertForbidden()
            ->assertExactJson([
                'status' => 403,
                'error' => 'Forbidden',
                'code' => 'write_action_not_allowed_for_organization',
            ]);
    });
});

it('allows the write when the api permission grants it', function (): void {
    withPremiumLicense(function (): void {
        config(['lago.license' => 'premium-license-token']);

        [$organization, $apiKey] = organizationWithApiKey();

        Illuminate\Support\Facades\DB::update(
            'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
            ['api_permissions', $organization->id],
        );
        Illuminate\Support\Facades\DB::update(
            'update api_keys set permissions = ?::jsonb where id = ?',
            [json_encode(['organization' => ['write']]), $apiKey->id],
        );

        $this->putJson('/api/v1/organizations', ['organization' => [
            'legal_name' => 'Allowed',
        ]], ['Authorization' => 'Bearer '.$apiKey->value])
            ->assertOk()
            ->assertJsonPath('organization.legal_name', 'Allowed');
    });
});

it('requires the organization param on update', function (): void {
    [, $apiKey] = organizationWithApiKey();

    $this->putJson('/api/v1/organizations', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertBadRequest()
        ->assertExactJson([
            'status' => 400,
            'error' => 'BadRequest: param is missing or the value is empty or invalid: organization',
        ]);
});

it('rejects an invalid document_numbering on update', function (): void {
    [$organization, $apiKey] = organizationWithApiKey();

    $this->putJson('/api/v1/organizations', ['organization' => [
        'document_numbering' => 'not_a_numbering',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertExactJson([
            'status' => 422,
            'error' => 'Unprocessable Entity',
            'code' => 'validation_errors',
            'error_details' => ['document_numbering' => ['value_is_invalid']],
        ]);
});

it('does not update with unpermitted params', function (): void {
    [$organization, $apiKey] = organizationWithApiKey();

    $this->putJson('/api/v1/organizations', ['organization' => [
        'name' => 'Hacked Name',
        'legal_name' => 'Permitted Legal',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('organization.name', $organization->name)
        ->assertJsonPath('organization.legal_name', 'Permitted Legal');
});

it('creates a webhook endpoint from the webhook_url param', function (): void {
    [$organization, $apiKey] = organizationWithApiKey();

    $this->putJson('/api/v1/organizations', ['organization' => [
        'webhook_url' => 'https://example.com/webhooks',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('organization.webhook_url', 'https://example.com/webhooks')
        ->assertJsonPath('organization.webhook_urls', ['https://example.com/webhooks']);

    expect($organization->webhookEndpoints()->where('webhook_url', 'https://example.com/webhooks')->exists())->toBeTrue();
});

it('requires an api key at all', function (): void {
    $this->getJson('/api/v1/organizations')->assertUnauthorized();
});
