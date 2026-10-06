<?php

declare(strict_types=1);

namespace App\GraphQL\Unions;

use App\Models\Invoice;
use App\Models\PaymentRequest;

/**
 * Type resolver for the frozen SDL's `union Payable` — the payable morph of
 * a Payment: `Invoice` for invoice payments, `PaymentRequest` for the dunning
 * payment links.
 */
class Payable extends AbstractLagoUnionTypeResolver
{
    public function __invoke(mixed $root): \GraphQL\Type\Definition\Type
    {
        if ($root instanceof Invoice) {
            return $this->schemaType('Invoice');
        }

        if ($root instanceof PaymentRequest) {
            return $this->schemaType('PaymentRequest');
        }

        return parent::__invoke($root);
    }
}
