<?php

declare(strict_types=1);

namespace App\Services\Events;

use RuntimeException;

/**
 * Stands in for ActiveRecord's RecordInvalid on the Event model: carries
 * the validation messages hash the service renders as the validation
 * failure (Rails: `record_validation_failure!(record: e.record)`).
 *
 * @property array<string, list<string>> $messages
 */
class EventValidation extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $messages
     */
    public function __construct(public readonly array $messages)
    {
        parent::__construct('Validation errors: '.json_encode($messages));
    }
}
