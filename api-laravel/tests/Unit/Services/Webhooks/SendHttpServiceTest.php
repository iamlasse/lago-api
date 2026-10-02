<?php

declare(strict_types=1);

use App\Models\Webhook;
use App\Models\Organization;
use App\Models\WebhookEndpoint;
use App\Jobs\SendHttpWebhookJob;
use App\Http\Client\LagoHttpClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use App\Services\Webhooks\SendHttpService;
use Illuminate\Http\Client\ConnectionException;

// Rails' spec_helper.rb: ENV["LAGO_WEBHOOK_ALLOW_PRIVATE_URLS"] ||= "true".
// The SSRF guard is exercised explicitly in the guard tests below and in
// tests/Unit/Http/Client/AddressGuardTest.php.
beforeEach(function () {
    $_ENV['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'] = 'true';
    $_SERVER['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'] = 'true';

    Queue::fake();
});

afterEach(function () {
    unset($_ENV['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'], $_SERVER['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS']);
});

beforeEach(function () {
    $this->endpoint = WebhookEndpoint::factory()->create([
        'webhook_url' => 'https://wh.test.com',
        // HMAC by default in this suite; the JWT tests generate their own key.
        'signature_algo' => 1,
    ]);
    $this->organization = Organization::findOrFail($this->endpoint->organization_id);
    $this->webhook = Webhook::factory()
        ->for($this->endpoint, 'webhookEndpoint')
        ->for($this->organization, 'organization')
        ->create([
            'webhook_type' => 'invoice.created',
            'endpoint' => 'https://wh.test.com',
            'payload' => ['webhook_type' => 'invoice.created', 'object_type' => 'invoice'],
        ]);
});

// -- Success -------------------------------------------------------------------

it('marks the webhook as succeeded', function () {
    Http::fake(['https://wh.test.com' => Http::response('ok', 200)]);

    SendHttpService::call(webhook: $this->webhook);

    $webhook = $this->webhook->fresh();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://wh.test.com'
            && $request->method() === 'POST'
            && $request->body() === json_encode($this->webhook->payload, JSON_UNESCAPED_SLASHES);
    });

    expect($webhook->succeeded())->toBeTrue()
        ->and($webhook->http_status)->toBe(200)
        ->and($webhook->responseJson())->toBe('ok')
        ->and($webhook->retries)->toBe(0);

    Queue::assertNothingPushed();
});

it('sends the signature headers', function () {
    $this->endpoint->update(['signature_algo' => 1]); // :hmac
    Http::fake(['https://wh.test.com' => Http::response('ok', 200)]);

    SendHttpService::call(webhook: $this->webhook);

    $expectedSignature = base64_encode(hash_hmac(
        'sha256',
        json_encode($this->webhook->payload, JSON_UNESCAPED_SLASHES),
        $this->organization->hmac_key,
        true,
    ));

    Http::assertSent(function ($request) use ($expectedSignature) {
        return $request->hasHeader('X-Lago-Signature', $expectedSignature)
            && $request->hasHeader('X-Lago-Signature-Algorithm', 'hmac')
            && $request->hasHeader('X-Lago-Unique-Key', $this->webhook->id)
            && $request->hasHeader('Content-Type', 'application/json');
    });
});

it('re-points the endpoint at the endpoint record url before sending', function () {
    $this->endpoint->update(['webhook_url' => 'https://wh-updated.test.com']);
    Http::fake(['https://wh-updated.test.com' => Http::response('ok', 200)]);

    SendHttpService::call(webhook: $this->webhook);

    expect($this->webhook->fresh()->endpoint)->toBe('https://wh-updated.test.com');
});

// -- HTTP error ----------------------------------------------------------------

it('creates a retrying webhook on an http error', function () {
    Http::fake(['https://wh.test.com' => Http::response(['message' => 'forbidden'], 403)]);

    SendHttpService::call(webhook: $this->webhook);

    $webhook = $this->webhook->fresh();

    expect($webhook->retrying())->toBeTrue()
        ->and($webhook->http_status)->toBe(403)
        ->and($webhook->responseJson())->toBe('{"message":"forbidden"}')
        ->and($webhook->retries)->toBe(1)
        ->and($webhook->last_retried_at)->not->toBeNull();

    Queue::assertPushed(SendHttpWebhookJob::class, 1);
});

it('keeps retrying an already retried webhook', function () {
    $this->webhook->update(['retries' => 1, 'status' => 3]); // :retrying
    Http::fake(['https://wh.test.com' => Http::response('nope', 403)]);

    SendHttpService::call(webhook: $this->webhook);

    $webhook = $this->webhook->fresh();

    expect($webhook->retrying())->toBeTrue()
        ->and($webhook->http_status)->toBe(403)
        ->and($webhook->retries)->toBe(2);

    Queue::assertPushed(SendHttpWebhookJob::class, 1);
});

it('fails the webhook after the attempt limit and stops re-enqueueing', function () {
    $this->webhook->update(['retries' => 2, 'status' => 3]); // :retrying
    Http::fake(['https://wh.test.com' => Http::response('nope', 403)]);

    SendHttpService::call(webhook: $this->webhook);

    $webhook = $this->webhook->fresh();

    expect($webhook->failed())->toBeTrue()
        ->and($webhook->http_status)->toBe(403)
        ->and($webhook->retries)->toBe(3);

    Queue::assertNothingPushed();
});

it('honours a configured attempt limit', function () {
    config(['lago.webhook.attempts' => 2]);
    $this->webhook->update(['retries' => 1, 'status' => 3]);
    Http::fake(['https://wh.test.com' => Http::response('nope', 500)]);

    SendHttpService::call(webhook: $this->webhook);

    expect($this->webhook->fresh()->failed())->toBeTrue();

    Queue::assertNothingPushed();
});

// -- Connection failures ---------------------------------------------------------

it('stores a generic message when the connection fails', function () {
    Http::fake(function () {
        throw new ConnectionException('cURL error 7: Failed to connect');
    });

    SendHttpService::call(webhook: $this->webhook);

    $webhook = $this->webhook->fresh();

    expect($webhook->retrying())->toBeTrue()
        ->and($webhook->responseJson())->toBe('Connection failed')
        ->and($webhook->http_status)->toBeNull();

    Queue::assertPushed(SendHttpWebhookJob::class, 1);
});

it('does not send the webhook when the endpoint resolves to a private address', function () {
    unset($_ENV['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'], $_SERVER['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS']);
    $this->endpoint->update(['webhook_url' => 'http://127.0.0.1:9381/hook']);
    $this->webhook->update(['endpoint' => 'http://127.0.0.1:9381/hook']);
    Http::fake();

    SendHttpService::call(webhook: $this->webhook);

    Http::assertNothingSent();

    $webhook = $this->webhook->fresh();

    expect($webhook->retrying())->toBeTrue()
        ->and($webhook->responseJson())->toBe('Destination address is not allowed');
});

it('sends when LAGO_WEBHOOK_ALLOW_PRIVATE_URLS allows a private address', function () {
    $this->endpoint->update(['webhook_url' => 'http://127.0.0.1:9381/hook']);
    $this->webhook->update(['endpoint' => 'http://127.0.0.1:9381/hook']);
    Http::fake(['http://127.0.0.1:9381/hook' => Http::response('ok', 200)]);

    SendHttpService::call(webhook: $this->webhook);

    expect($this->webhook->fresh()->succeeded())->toBeTrue();
});

// -- Response capping and scrubbing ------------------------------------------------

it('caps the stored response at 64KB', function () {
    Http::fake(['https://wh.test.com' => Http::response(str_repeat('a', SendHttpService::MAX_STORED_RESPONSE_BYTES + 10), 200)]);

    SendHttpService::call(webhook: $this->webhook);

    expect($this->webhook->fresh()->responseJson())
        ->toBe(str_repeat('a', SendHttpService::MAX_STORED_RESPONSE_BYTES));
});

it('drops a multibyte character cut by the byte cap', function () {
    // 'é' is 2 bytes; put it straddling the 64KB boundary.
    $body = str_repeat('a', SendHttpService::MAX_STORED_RESPONSE_BYTES - 1).'é';
    Http::fake(['https://wh.test.com' => Http::response($body, 200)]);

    SendHttpService::call(webhook: $this->webhook);

    expect($this->webhook->fresh()->succeeded())->toBeTrue()
        ->and($this->webhook->fresh()->responseJson())->toBe(str_repeat('a', SendHttpService::MAX_STORED_RESPONSE_BYTES - 1));
});

it('stores the valid part of a non-UTF-8 response', function () {
    Http::fake(['https://wh.test.com' => Http::response("ok\xFF", 200)]);

    SendHttpService::call(webhook: $this->webhook);

    expect($this->webhook->fresh()->succeeded())->toBeTrue()
        ->and($this->webhook->fresh()->responseJson())->toBe('ok');
});

// -- Backoff ---------------------------------------------------------------------

it('computes the exponential backoff with jitter', function () {
    $service = new SendHttpService(webhook: $this->webhook);
    $waitValue = new ReflectionMethod($service, 'waitValue');

    $this->webhook->retries = 1;
    expect($waitValue->invoke($service))->toBeGreaterThanOrEqual(3.0)
        ->and($waitValue->invoke($service))->toBeLessThanOrEqual(3.15);

    $this->webhook->retries = 2;
    $wait = $waitValue->invoke($service);
    expect($wait)->toBeGreaterThanOrEqual(18.0)
        ->and($wait)->toBeLessThanOrEqual(20.4);

    $this->webhook->retries = 3;
    $wait = $waitValue->invoke($service);
    expect($wait)->toBeGreaterThanOrEqual(83.0)
        ->and($wait)->toBeLessThanOrEqual(83 + 81 * 0.15);
});

it('enqueues the retry with a delay', function () {
    Http::fake(['https://wh.test.com' => Http::response('nope', 403)]);

    SendHttpService::call(webhook: $this->webhook);

    Queue::assertPushed(SendHttpWebhookJob::class, function ($job) {
        return $job->delay instanceof DateTimeInterface;
    });
});

// -- Client construction -----------------------------------------------------------

it('builds the http client with the configured timeouts and the SSRF guard', function () {
    config(['lago.webhook.timeout_seconds' => 45]);

    $service = new SendHttpService(webhook: $this->webhook);
    $client = (new ReflectionMethod($service, 'httpClient'))->invoke($service);

    expect($client)->toBeInstanceOf(LagoHttpClient::class)
        ->and($client->openTimeout)->toBe(45)
        ->and($client->readTimeout)->toBe(45)
        ->and($client->writeTimeout)->toBe(45)
        ->and($client->blockPrivateAddresses)->toBeTrue();
});
