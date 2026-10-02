<?php

declare(strict_types=1);

namespace App\Http\Client;

/**
 * Port of LagoHttpClient::HttpError
 * (lib/lago_http_client/lago_http_client/http_error.rb) — raised for any
 * response outside RESPONSE_SUCCESS_CODES.
 */
class LagoHttpError extends \RuntimeException
{
    public function __construct(
        public readonly int|string $errorCode,
        public readonly mixed $errorBody,
        public readonly ?string $uri = null,
        /** @var array<string, string> */
        public readonly array $responseHeaders = [],
    ) {
        parent::__construct(
            "HTTP {$errorCode} - URI: {$uri}.\nError: ".
            (is_scalar($errorBody) ? (string) $errorBody : json_encode($errorBody)).
            "\nResponse headers: ".json_encode($responseHeaders),
        );
    }
}
