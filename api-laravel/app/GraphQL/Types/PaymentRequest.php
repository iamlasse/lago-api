<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\PaymentRequest as PaymentRequestModel;

/**
 * Field resolvers for the frozen SDL's `PaymentRequest` type (port of Rails'
 * Types::PaymentRequests::Object computed fields). Plain columns resolve
 * through the snake_case attribute fallback.
 */
class PaymentRequest
{
    /** Rails: payment_status — the enum wire name (column stores the position). */
    public function paymentStatus(PaymentRequestModel $root): string
    {
        return $root->paymentStatus();
    }

    /** Rails: payable_type — the literal "PaymentRequest". */
    public function payableType(PaymentRequestModel $root): string
    {
        return 'PaymentRequest';
    }

    /** Rails: invoices — the invoices the request applies to. */
    public function invoices(PaymentRequestModel $root): \Illuminate\Support\Collection
    {
        return $root->invoices()->get();
    }
}
