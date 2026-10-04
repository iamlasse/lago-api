<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\PaymentReceipt as PaymentReceiptModel;

/**
 * Field resolvers for the frozen SDL's `PaymentReceipt` type (port of
 * Rails' Types::PaymentReceipts::Object — the fileUrl/xmlUrl accessors).
 * Plain columns resolve through the snake_case attribute fallback.
 */
class PaymentReceipt
{
    /** Rails: file_url — nil while no PDF is attached. */
    public function fileUrl(PaymentReceiptModel $root): ?string
    {
        return $root->fileUrl();
    }

    /** Rails: xml_url — nil while no XML is attached. */
    public function xmlUrl(PaymentReceiptModel $root): ?string
    {
        return $root->xmlUrl();
    }
}
