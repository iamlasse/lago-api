<?php

declare(strict_types=1);

namespace App\Services\QuoteVersions\Validators;

use App\Models\Quote;
use App\Models\QuoteVersion;
use App\Services\BaseResult;

/**
 * Port of Rails' QuoteVersions::Validators
 * (app/services/quote_versions/validators.rb) — dispatches on the quote's
 * order type. A quote with an unknown order type validates to nothing
 * (Rails: `case` falls through to nil, the caller treats a nil validator as
 * valid).
 */
class Validators
{
    public static function for(BaseResult $result, QuoteVersion $quoteVersion, string $scope): ?BaseOrderTypeValidator
    {
        $orderType = $quoteVersion->quote->order_type;

        return match (true) {
            $orderType === Quote::ORDER_TYPES['one_off'] => new OneOffValidator($result, $quoteVersion, $scope),
            $orderType === Quote::ORDER_TYPES['subscription_creation'] => new SubscriptionCreationValidator($result, $quoteVersion, $scope),
            $orderType === Quote::ORDER_TYPES['subscription_amendment'] => new SubscriptionAmendmentValidator($result, $quoteVersion, $scope),
            default => null,
        };
    }
}
