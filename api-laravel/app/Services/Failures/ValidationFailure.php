<?php

declare(strict_types=1);

namespace App\Services\Failures;

/**
 * Port of BaseService::ValidationFailure — carries the errors hash
 * (field => [codes]), formatted into the message like Rails
 * ("Validation errors: {json}").
 *
 * @property array<string, list<string>> $messages
 */
class ValidationFailure extends FailedResult
{
    /** @param array<string, list<string>> $messages */
    public function __construct(object $result, public readonly array $messages)
    {
        parent::__construct($result, 'Validation errors: '.json_encode($messages));
    }
}
