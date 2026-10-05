<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Adyen;

/** Port of ::Adyen::AuthenticationError (401 responses). */
class AuthenticationError extends AdyenError {}
