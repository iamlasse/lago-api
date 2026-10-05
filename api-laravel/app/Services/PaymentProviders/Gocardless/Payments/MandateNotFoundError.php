<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Gocardless\Payments;

use RuntimeException;

/** Port of Rails' Gocardless::Payments::CreateService::MandateNotFoundError. */
class MandateNotFoundError extends RuntimeException
{
    public const DEFAULT_MESSAGE = 'No mandate available for payment';

    public const ERROR_CODE = 'no_mandate_error';

    public function __construct(string $message = self::DEFAULT_MESSAGE)
    {
        parent::__construct($message);
    }
}
