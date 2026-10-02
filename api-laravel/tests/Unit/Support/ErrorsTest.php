<?php

declare(strict_types=1);

use App\GraphQL\Execution\Errors;
use App\GraphQL\Exceptions\ExecutionError;

/**
 * Port of the ExecutionErrorResponder expectations from Rails' graphql specs
 * (expect_unprocessable_entity / expect_not_found helpers in
 * spec/support/graphql_helper.rb).
 */
it('builds a validation error with the unprocessable_entity shape', function () {
    $error = Errors::validationError(['base' => ['incorrect_login_or_password']]);

    expect($error)->toBeInstanceOf(ExecutionError::class)
        ->and($error->getMessage())->toBe('Unprocessable Entity')
        ->and($error->getExtensions())->toBe([
            'status' => 422,
            'code' => 'unprocessable_entity',
            'details' => ['base' => ['incorrect_login_or_password']],
        ]);
});

it('builds a not found error keyed by resource', function () {
    $error = Errors::notFoundError('customer');

    expect($error->getMessage())->toBe('Resource not found')
        ->and($error->getExtensions())->toBe([
            'status' => 404,
            'code' => 'not_found',
            'details' => ['customer' => ['not_found']],
        ]);
});

it('builds a not allowed error', function () {
    $error = Errors::notAllowedError('signup_disabled');

    expect($error->getMessage())->toBe('Method Not Allowed')
        ->and($error->getExtensions()['status'])->toBe(405)
        ->and($error->getExtensions()['code'])->toBe('signup_disabled');
});

it('builds a forbidden error', function () {
    $error = Errors::forbiddenError('permissions_failure');

    expect($error->getMessage())->toBe('forbidden')
        ->and($error->getExtensions())->toBe([
            'status' => 403,
            'code' => 'permissions_failure',
        ]);
});

it('builds a third party failure wrapping messages under error', function () {
    $error = Errors::thirdPartyFailure(['provider exploded']);

    expect($error->getExtensions())->toBe([
        'status' => 422,
        'code' => 'third_party_error',
        'details' => ['error' => ['provider exploded']],
    ]);
});

it('lowerCamelizes details keys like Rails camelize(:lower)', function () {
    $error = Errors::validationError([
        'external_customer_id' => ['cant_be_blank'],
        'name' => ['already_exists'],
        'nested' => ['legal_number' => ['invalid']],
    ]);

    expect($error->getExtensions()['details'])->toBe([
        'externalCustomerId' => ['cant_be_blank'],
        'name' => ['already_exists'],
        'nested' => ['legalNumber' => ['invalid']],
    ]);
});

it('leaves message arrays untouched while camelizing keys only', function () {
    $error = Errors::validationError(['base' => ['incorrect_login_or_password']]);

    expect($error->getExtensions()['details']['base'])->toBe(['incorrect_login_or_password']);
});
