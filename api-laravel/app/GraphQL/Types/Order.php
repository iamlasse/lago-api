<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Order as OrderModel;

/**
 * Field resolvers for the frozen SDL's `Order` type (port of Rails'
 * Types::Orders::Object computed fields). Plain columns resolve through the
 * snake_case attribute fallback; the execution_record hash is completed
 * with the EXECUTION_RECORD_DEFAULTS exactly like V1::OrderSerializer.
 */
class Order
{
    /** Rails: Types::Orders::ExecutionRecord — the defaults merge. */
    public function executionRecord(OrderModel $root): array
    {
        /** @var array<string, mixed>|null $record */
        $record = $root->execution_record;

        // camelCase keys: the frozen SDL field names resolve on the hash
        // directly (webonyx exact-name lookup).
        return [
            'executedAt' => $record['executed_at'] ?? null,
            'executionMode' => $record['execution_mode'] ?? null,
            'invoiceId' => $record['invoice_id'] ?? null,
            'subscriptionIds' => $record['subscription_ids'] ?? [],
            'terminatedSubscriptionIds' => $record['terminated_subscription_ids'] ?? [],
            'appliedCouponIds' => $record['applied_coupon_ids'] ?? [],
            'walletIds' => $record['wallet_ids'] ?? [],
            'errors' => $record['errors'] ?? [],
        ];
    }

    /** Rails: object.billing_snapshot — the quote version billing items. */
    public function billingSnapshot(OrderModel $root): ?array
    {
        return $root->billingSnapshot();
    }

    /** Rails: delegate :currency, to: :quote_version. */
    public function currency(OrderModel $root): ?string
    {
        return $root->currency();
    }

    /** Rails: delegate :order_type, to: :quote. */
    public function orderType(OrderModel $root): ?string
    {
        return $root->orderType();
    }

    /** Rails: the status enum name. */
    public function status(OrderModel $root): ?string
    {
        return $root->status?->label();
    }

    /** Rails: the execution_mode enum name. */
    public function executionMode(OrderModel $root): ?string
    {
        return $root->execution_mode?->label();
    }

    // -- Unported features (stubs, see FULL_SCHEMA_NOTES.md) -------------------

    /** Rails: activity_logs — the ClickHouse activity log slice. */
    public function activityLogs(OrderModel $root): ?array
    {
        return null;
    }
}
