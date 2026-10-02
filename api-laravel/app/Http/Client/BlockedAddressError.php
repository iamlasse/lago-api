<?php

declare(strict_types=1);

namespace App\Http\Client;

/**
 * Port of LagoHttpClient::BlockedAddressError
 * (lib/lago_http_client/lago_http_client/blocked_address_error.rb).
 */
class BlockedAddressError extends \RuntimeException
{
    public function __construct(string $host)
    {
        parent::__construct("Destination address is not allowed: {$host}");
    }
}
