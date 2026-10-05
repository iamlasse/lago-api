<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Adyen;

/** Port of ::Adyen::ValidationError (invalid request payloads). */
class ValidationError extends AdyenError {}
