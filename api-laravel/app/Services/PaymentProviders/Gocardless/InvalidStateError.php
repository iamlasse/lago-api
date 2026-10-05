<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Gocardless;

/** Port of GoCardlessPro::InvalidStateError (cancellation_failed etc.). */
class InvalidStateError extends GoCardlessError {}
