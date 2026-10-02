<?php

use App\Support\CurrentContext;

/**
 * Error envelope contract (port of Rails' api_errors.rb / api_responses.rb
 * + ApplicationController#not_found catch-all).
 */
->group('ledger:rest:error_envelope');

it('renders the 404 envelope for unmatched api routes', function () {
    $this->getJson('/api/v1/definitely/not/a/route')
        ->assertNotFound()
        ->assertExactJson([
            'status' => 404,
            'error' => 'Not Found',
            'code' => 'resource_not_found',
        ]);
});

it('renders the 404 envelope for the api root and carries the beta header on v2', function () {
    $this->getJson('/api/v1')
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'resource_not_found']);

    $this->getJson('/api/v2/nope')
        ->assertNotFound()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'resource_not_found']);
});

it('renders a 400 bad request envelope for missing required params', function () {
    $organization = App\Models\Organization::create(['name' => 'Envelope Org']);
    $apiKey = App\Models\ApiKey::create([
        'organization_id' => $organization->id,
        'value' => (string) Illuminate\Support\Str::uuid(),
        'permissions' => [],
    ]);

    $response = $this->postJson('/api/v1/placeholder', [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ]);

    // Port of ApiErrors#bad_request_error + ActionController::ParameterMissing.
    $response->assertBadRequest()
        ->assertExactJson([
            'status' => 400,
            'error' => 'BadRequest: param is missing or the value is empty or invalid: input',
        ]);
});

it('renders the exception envelopes with a body status matching the http status', function () {
    expect((new App\Exceptions\Api\NotFoundException('customer'))->body())->toBe([
        'status' => 404,
        'error' => 'Not Found',
        'code' => 'customer_not_found',
    ])->and((new App\Exceptions\Api\UnauthorizedException)->body())->toBe([
        'status' => 401,
        'error' => 'Unauthorized',
    ])->and((new App\Exceptions\Api\ForbiddenException('read_action_not_allowed_for_plans'))->body())->toBe([
        'status' => 403,
        'error' => 'Forbidden',
        'code' => 'read_action_not_allowed_for_plans',
    ])->and((new App\Exceptions\Api\ValidationException(['name' => ['error_blank']]))->body())->toBe([
        'status' => 422,
        'error' => 'Unprocessable Entity',
        'code' => 'validation_errors',
        'error_details' => ['name' => ['error_blank']],
    ])->and((new App\Exceptions\Api\MethodNotAllowedException('endpoint_not_available'))->body())->toBe([
        'status' => 405,
        'error' => 'Method Not Allowed',
        'code' => 'endpoint_not_available',
    ])->and((new App\Exceptions\Api\BadRequestException('nope'))->body())->toBe([
        'status' => 400,
        'error' => 'BadRequest: nope',
    ]);
});

it('resets CurrentContext between requests', function () {
    CurrentContext::reset();

    expect(CurrentContext::$source)->toBeNull();
});
