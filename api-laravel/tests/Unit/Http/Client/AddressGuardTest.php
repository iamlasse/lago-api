<?php

declare(strict_types=1);

use App\Http\Client\AddressGuard;
use App\Http\Client\LagoHttpError;
use App\Http\Client\LagoHttpClient;
use Illuminate\Support\Facades\Http;
use App\Http\Client\BlockedAddressError;
use Illuminate\Http\Client\ConnectionException;

/**
 * Port of spec/lib/lago_http_client/address_guard_spec.rb (SSRF vectors).
 */
it('blocks private and special-purpose IPv4 ranges', function (string $ip): void {
    expect(AddressGuard::blockedIp($ip))->toBeTrue();
})->with([
    'this-network' => '0.0.0.0',
    'private-10' => '10.1.2.3',
    'cgnat' => '100.64.0.1',
    'loopback' => '127.0.0.1',
    'link-local' => '169.254.169.254',
    'private-172' => '172.16.0.5',
    'private-192' => '192.168.1.1',
    'benchmarking' => '198.18.0.1',
    'documentation' => '192.0.2.1',
    'multicast' => '224.0.0.1',
    'reserved' => '240.0.0.1',
]);

it('blocks non-global and special-purpose IPv6 addresses', function (string $ip): void {
    expect(AddressGuard::blockedIp($ip))->toBeTrue();
})->with([
    'loopback' => '::1',
    'ula' => 'fd00::1',
    'link-local' => 'fe80::1',
    'documentation' => '2001:db8::1',
    'iamp-2001' => '2001:1::1',
    '6to4' => '2002:c000:201::1',
    'documentation-3fff' => '3fff::1',
    'nat64-embedded-private' => '64:ff9b::a00:1', // 10.0.0.1
]);

it('allows global unicast addresses', function (string $ip): void {
    expect(AddressGuard::blockedIp($ip))->toBeFalse();
})->with([
    'public-v4' => '8.8.8.8',
    'public-v4-2' => '1.1.1.1',
    'global-v6' => '2600::1',
]);

it('is enabled by default and honors the override', function (): void {
    expect(AddressGuard::enabled())->toBeTrue();

    $_ENV['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'] = 'true';
    $_SERVER['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'] = 'true';

    try {
        expect(AddressGuard::enabled())->toBeFalse();
    } finally {
        unset($_ENV['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'], $_SERVER['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS']);
    }
});

it('raises the blocked address error for loopback hosts', function (): void {
    AddressGuard::resolve('localhost');
})->throws(BlockedAddressError::class);

it('raises a connection error for hosts that do not resolve', function (): void {
    AddressGuard::resolve('lago-does-not-exist.invalid');
})->throws(ConnectionException::class);

it('resolves public hosts', function (): void {
    // DNS may be unavailable in the environment; only assert the error
    // contract, not the address.
    try {
        $address = AddressGuard::resolve('example.com');
        expect(filter_var($address, FILTER_VALIDATE_IP) !== false)->toBeTrue();
    } catch (ConnectionException) {
        expect(true)->toBeTrue();
    }
});

// -- LagoHttpClient surface -------------------------------------------------------

it('raises LagoHttpError for non-success codes', function (): void {
    Http::fake(['https://wh.test.com' => Http::response('nope', 500)]);

    $client = new LagoHttpClient('https://wh.test.com', readTimeout: 30);

    try {
        $client->postWithResponse(['a' => 'b'], ['X-Test' => '1']);
        $this->fail('expected LagoHttpError');
    } catch (LagoHttpError $e) {
        expect($e->errorCode)->toBe(500)
            ->and($e->errorBody)->toBe('nope');
    }
});

it('posts the json body with the given headers', function (): void {
    Http::fake(['https://wh.test.com' => Http::response('ok', 200)]);

    $client = new LagoHttpClient('https://wh.test.com');
    $client->postWithResponse(['a' => 'b'], ['X-Test' => '1']);

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request->body() === '{"a":"b"}'
            && $request->hasHeader('X-Test', '1')
            && $request->hasHeader('Content-Type', 'application/json');
    });
});

it('blocks the request before it is sent for private targets', function (): void {
    Http::fake();

    $client = new LagoHttpClient('http://192.168.0.1:9/hook', blockPrivateAddresses: true);

    try {
        $client->postWithResponse(['a' => 'b'], []);
        $this->fail('expected BlockedAddressError');
    } catch (BlockedAddressError) {
    }

    Http::assertNothingSent();
});
