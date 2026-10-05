<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Gocardless;

use Exception;
use Throwable;

/** Port of the GoCardlessPro::Error taxonomy the Rails services rescue. */
class GoCardlessError extends Exception
{
    public function __construct(
        string $message,
        // \Exception carries its own untyped int $code, so this shadow
        // cannot be typed or readonly.
        public $code = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
