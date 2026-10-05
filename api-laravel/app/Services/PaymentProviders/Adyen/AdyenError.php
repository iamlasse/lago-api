<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Adyen;

use Exception;

/**
 * Port of the adyen-ruby-api-library exception taxonomy the Rails services
 * rescue (::Adyen::AdyenError, ::Adyen::AuthenticationError,
 * ::Adyen::ValidationError) — a single error class carrying msg/code,
 * with subclasses standing in for the rescued types.
 */
class AdyenError extends Exception
{
    public function __construct(
        public readonly string $msg = '',
        // \Exception carries its own untyped int $code, so this shadow
        // cannot be typed or readonly.
        public $code = '',
    ) {
        parent::__construct($msg);
    }
}
