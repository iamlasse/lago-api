<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\OrderForm as OrderFormModel;

/**
 * Field resolvers for the frozen SDL's `OrderForm` type (port of Rails'
 * Types::OrderForms::Object computed fields).
 */
class OrderForm
{
    /** Rails: billing_snapshot — the quote version billing items. */
    public function billingSnapshot(OrderFormModel $root): ?array
    {
        return $root->quoteVersion?->billing_items;
    }

    /**
     * Rails: signed_document_url — the attached document's blob URL.
     * ActiveStorage attachments are not ported for order forms yet.
     *
     * TODO(port): has_one_attached :signed_document + rails_blob_url.
     */
    public function signedDocumentUrl(OrderFormModel $root): ?string
    {
        return null;
    }

    /** Rails: activity_logs — the ClickHouse activity log slice. */
    public function activityLogs(OrderFormModel $root): ?array
    {
        return null;
    }

    /**
     * Rails: `has_one :quote, through: :quote_version`. Resolved here rather
     * than through the attribute fallback: the model's helper methods return
     * records, not Eloquent relations, which the generic snake_case fallback
     * rejects ("must return a relationship instance").
     */
    public function quote(OrderFormModel $root): ?\App\Models\Quote
    {
        return $root->quoteVersion?->quote;
    }

    /** Rails: `has_one :order`. */
    public function order(OrderFormModel $root): ?\App\Models\Order
    {
        return $root->order();
    }
}
