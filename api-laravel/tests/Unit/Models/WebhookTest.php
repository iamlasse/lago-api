<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use App\Models\Webhook;
use App\Enums\WebhookStatus;
use App\Models\Organization;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    $this->endpoint = WebhookEndpoint::factory()->create();
    $this->organization = Organization::findOrFail($this->endpoint->organization_id);
    $this->webhook = Webhook::factory()
        ->for($this->endpoint, 'webhookEndpoint')
        ->for($this->organization, 'organization')
        ->create([
            'payload' => [
                'webhook_type' => 'customer.created',
                'object_type' => 'customer',
                'organization_id' => $this->organization->id,
                'customer' => ['lago_id' => 'cus-1'],
            ],
            'webhook_type' => 'customer.created',
            'endpoint' => $this->endpoint->webhook_url,
        ]);
});

it('computes the HMAC signature over the payload JSON with the organization hmac key', function (): void {
    // Rails: Base64.strict_encode64(OpenSSL::HMAC.digest("sha-256",
    // organization.hmac_key, payload.to_json)) — computed independently here.
    $expected = base64_encode(hash_hmac(
        'sha256',
        json_encode($this->webhook->payload, JSON_UNESCAPED_SLASHES),
        $this->organization->hmac_key,
        true,
    ));

    $this->endpoint->update(['signature_algo' => 1]); // :hmac

    expect($this->webhook->fresh()->hmacSignature())->toBe($expected);
});

it('generates the HMAC signature headers', function (): void {
    $this->endpoint->update(['signature_algo' => 1]); // :hmac

    $headers = $this->webhook->fresh()->generateHeaders();

    expect($headers)->toHaveKeys(['X-Lago-Signature', 'X-Lago-Signature-Algorithm', 'X-Lago-Unique-Key'])
        ->and($headers['X-Lago-Signature'])->toBe($this->webhook->fresh()->hmacSignature())
        ->and($headers['X-Lago-Signature-Algorithm'])->toBe('hmac')
        ->and($headers['X-Lago-Unique-Key'])->toBe($this->webhook->id);
});

it('generates the JWT signature headers and signs the payload with RS256', function (): void {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privatePem);
    $details = openssl_pkey_get_details($key);
    $publicPem = $details['key'];

    $path = tempnam(sys_get_temp_dir(), 'lago-rsa-').'.pem';
    file_put_contents($path, $privatePem);
    Config::set('lago.webhook.rsa_private_key_path', $path);

    expect(is_string($privatePem))->toBeTrue()
        ->and(mb_strlen(file_get_contents($path) ?: ''))->toBeGreaterThan(500)
        ->and(config('lago.webhook.rsa_private_key_path'))->toBe($path)
        ->and(openssl_pkey_get_private(file_get_contents($path)) !== false)->toBeTrue()
        ->and(JWT::encode(['data' => 'x'], file_get_contents($path), 'RS256'))->toContain('eyJ');

    Config::set('lago.api_url', 'https://api.getlago.com');
    $this->endpoint->update(['signature_algo' => 0]); // :jwt

    $webhook = $this->webhook->fresh();

    $headers = $webhook->generateHeaders();

    expect($headers['X-Lago-Signature-Algorithm'])->toBe('jwt')
        ->and($headers['X-Lago-Unique-Key'])->toBe($this->webhook->id);

    $decoded = JWT::decode($headers['X-Lago-Signature'], new Firebase\JWT\Key($publicPem, 'RS256'));

    expect($decoded->data)->toBe(json_encode($this->webhook->payload, JSON_UNESCAPED_SLASHES))
        ->and($decoded->iss)->toBe('https://api.getlago.com');

    unlink($path);
});

it('exposes the Rails status helpers', function (): void {
    expect($this->webhook->pending())->toBeTrue()
        ->and($this->webhook->succeeded())->toBeFalse();

    $this->webhook->writeStatus('succeeded')->save();
    expect($this->webhook->fresh()->succeeded())->toBeTrue()
        ->and($this->webhook->fresh()->statusValue())->toBe(WebhookStatus::Succeeded->value);
});

it('stores and reads back a scalar JSON response', function (): void {
    $this->webhook->storeResponse('ok');
    $this->webhook->save();

    expect($this->webhook->fresh()->responseJson())->toBe('ok');

    $this->webhook->storeResponse(['message' => 'forbidden']);
    $this->webhook->save();

    expect($this->webhook->fresh()->responseJson())->toBe(['message' => 'forbidden']);
});
