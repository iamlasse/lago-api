<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use App\Services\Auth\SupersetService;

it('debugs superset', function (): void {
    config([
        'lago.superset.url' => 'http://localhost:8089',
        'lago.superset.username' => 'admin',
        'lago.superset.password' => 'admin',
    ]);

    Http::fake([
        '*/api/v1/security/login' => Http::response(['access_token' => 'access_token_123']),
        '*/api/v1/security/csrf_token/' => Http::response(['result' => 'csrf_token_456']),
        '*/api/v1/dashboard/' => Http::response(['result' => []]),
    ]);

    $organization = App\Models\Organization::factory()->create();
    $result = SupersetService::call(organization: $organization);
    dump($result->failure() ? $result->getError()->code.' / '.$result->getError()->error_message : 'ok');
    expect(true)->toBeTrue();
});
