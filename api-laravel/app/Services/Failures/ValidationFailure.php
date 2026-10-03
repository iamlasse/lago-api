<?php

declare(strict_types=1);

namespace App\Services\Failures;

/**
 * Port of BaseService::ValidationFailure — carries the errors hash
 * (field => [codes]), formatted into the message like Rails
 * ("Validation errors: {json}").
 *
 * @property array<string, list<string>>|object|string $messages
 */
class ValidationFailure extends FailedResult
{
    /**
     * @param  array<string, list<string>>|object|string  $messages
     *                                                               Rails passes any `errors` value through verbatim (a hash for
     *                                                               record validations, a bare string for service-level failures, an
     *                                                               index-keyed object for batch errors).
     */
    public function __construct(object $result, public readonly array|object|string $messages)
    {
        parent::__construct($result, 'Validation errors: '.json_encode($messages));
    }
}
