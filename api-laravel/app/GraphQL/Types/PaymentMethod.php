<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\PaymentMethod as PaymentMethodModel;

/**
 * Field resolvers for the frozen SDL's `PaymentMethod` type (port of Rails'
 * Types::PaymentMethods::Object computed fields). Plain columns resolve
 * through the snake_case attribute fallback.
 */
class PaymentMethod
{
    /** Rails: payment_provider_code — payment_provider&.code. */
    public function paymentProviderCode(PaymentMethodModel $root): ?string
    {
        return $root->paymentProvider?->code;
    }

    /** Rails: payment_provider_name — payment_provider&.name. */
    public function paymentProviderName(PaymentMethodModel $root): ?string
    {
        return $root->paymentProvider?->name;
    }

    /** Rails: payment_provider_type — the provider's payment_type slug. */
    public function paymentProviderType(PaymentMethodModel $root): ?string
    {
        return $root->paymentProviderType();
    }

    /** Rails: details — the provider-returned method details hash. */
    public function details(PaymentMethodModel $root): ?object
    {
        $details = $root->details;

        if (! is_array($details) || $details === []) {
            return null;
        }

        return (object) [
            'brand' => $details['brand'] ?? null,
            'expirationMonth' => isset($details['expiration_month']) ? (string) $details['expiration_month'] : null,
            'expirationYear' => isset($details['expiration_year']) ? (string) $details['expiration_year'] : null,
            'last4' => isset($details['last4']) ? (string) $details['last4'] : null,
            'type' => $details['type'] ?? null,
        ];
    }
}
