<?php

declare(strict_types=1);

namespace App\GraphQL\Exceptions;

use Exception;
use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesErrorExtensions;

/**
 * Port of GraphQL::ExecutionError with extensions `{status, code, details?}`
 * as built by Rails' ExecutionErrorResponder concern
 * (app/graphql/concerns/execution_error_responder.rb).
 *
 * `details` keys are lowerCamelized at construction time (Rails: `transform_keys
 * { |k| k.to_s.camelize(:lower) }`), so the extensions leave the API in the
 * exact wire shape the frontend expects.
 */
class ExecutionError extends Exception implements ClientAware, ProvidesErrorExtensions
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(
        string $error = 'Internal Error',
        public readonly int|string $status = 422,
        public readonly string $code = 'internal_error',
        public readonly ?array $details = null,
    ) {
        parent::__construct($error);
    }

    public function isClientSafe(): bool
    {
        return true;
    }

    /**
     * Extensions as they must appear on the GraphQL error, i.e. `status` keeps
     * its wire representation: integer HTTP statuses stay integers, Rails
     * symbol statuses (:unauthorized / :forbidden) serialize as strings.
     */
    public function getExtensions(): array
    {
        $payload = [
            'status' => $this->status,
            'code' => $this->code,
        ];

        if ($this->details !== null) {
            $payload['details'] = $this->details;
        }

        return $payload;
    }
}
