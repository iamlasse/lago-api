<?php

declare(strict_types=1);

namespace App\Services\ClickHouse\Logs;

use App\Models\Organization;
use App\Models\User;

/**
 * Port of Rails' Clickhouse::ActivityLog model (app/models/clickhouse/
 * activity_log.rb) + ActivityLogsQuery (app/queries/activity_logs_query.rb).
 *
 * Filters, in Rails' application order: retention (organization
 * audit_logs_period), logged_at from/to range, api_key_ids, activity_ids,
 * activity_types, activity_sources, user_emails (resolved to organization
 * user ids), external_customer_id, external_subscription_id, resource_ids,
 * resource_types.
 */
class ActivityLogQuery extends LogQuery
{
    /** Clickhouse::ActivityLog::RESOURCE_TYPES_WITH_DISCARDED. */
    public const RESOURCE_TYPES_WITH_DISCARDED = [
        'BillableMetric', 'Plan', 'CatalogPlan', 'Customer', 'BillingEntity',
        'Coupon', 'ProductCategory', 'Product', 'ProductFilter', 'RateCard',
    ];

    /** Clickhouse::ActivityLog::RESOURCE_TYPES — GraphQL enum name => stored class. */
    public const RESOURCE_TYPES = [
        'billable_metric' => 'BillableMetric',
        'plan' => 'Plan',
        'catalog_plan' => 'CatalogPlan',
        'customer' => 'Customer',
        'invoice' => 'Invoice',
        'credit_note' => 'CreditNote',
        'billing_entity' => 'BillingEntity',
        'subscription' => 'Subscription',
        'wallet' => 'Wallet',
        'coupon' => 'Coupon',
        'payment_receipt' => 'PaymentReceipt',
        'payment_request' => 'PaymentRequest',
        'feature' => 'Entitlement::Feature',
        'product_category' => 'ProductCategory',
        'product' => 'Product',
        'product_filter' => 'ProductFilter',
        'rate_card' => 'RateCard',
        'quote' => 'Quote',
        'order_form' => 'OrderForm',
        'order' => 'Order',
    ];

    /** Clickhouse::ActivityLog::ACTIVITY_TYPES — GraphQL enum name => stored value. */
    public const ACTIVITY_TYPES = [
        'billable_metric_created' => 'billable_metric.created',
        'billable_metric_updated' => 'billable_metric.updated',
        'billable_metric_deleted' => 'billable_metric.deleted',
        'plan_created' => 'plan.created',
        'plan_updated' => 'plan.updated',
        'plan_deleted' => 'plan.deleted',
        'customer_created' => 'customer.created',
        'customer_updated' => 'customer.updated',
        'customer_deleted' => 'customer.deleted',
        'invoice_drafted' => 'invoice.drafted',
        'invoice_ready_to_finalize' => 'invoice.ready_to_finalize',
        'invoice_failed' => 'invoice.failed',
        'invoice_created' => 'invoice.created',
        'invoice_one_off_created' => 'invoice.one_off_created',
        'invoice_paid_credit_added' => 'invoice.paid_credit_added',
        'invoice_generated' => 'invoice.generated',
        'invoice_payment_status_updated' => 'invoice.payment_status_updated',
        'invoice_payment_overdue' => 'invoice.payment_overdue',
        'invoice_voided' => 'invoice.voided',
        'invoice_deleted' => 'invoice.deleted',
        'invoice_regenerated' => 'invoice.regenerated',
        'invoice_payment_failure' => 'invoice.payment_failure',
        'payment_receipt_created' => 'payment_receipt.created',
        'payment_receipt_generated' => 'payment_receipt.generated',
        'credit_note_created' => 'credit_note.created',
        'credit_note_generated' => 'credit_note.generated',
        'credit_note_refund_failure' => 'credit_note.refund_failure',
        'billing_entities_created' => 'billing_entities.created',
        'billing_entities_updated' => 'billing_entities.updated',
        'billing_entities_deleted' => 'billing_entities.deleted',
        'subscription_canceled' => 'subscription.canceled',
        'subscription_incomplete' => 'subscription.incomplete',
        'subscription_started' => 'subscription.started',
        'subscription_terminated' => 'subscription.terminated',
        'subscription_updated' => 'subscription.updated',
        'wallet_created' => 'wallet.created',
        'wallet_updated' => 'wallet.updated',
        'wallet_transaction_payment_failure' => 'wallet_transaction.payment_failure',
        'wallet_transaction_created' => 'wallet_transaction.created',
        'wallet_transaction_updated' => 'wallet_transaction.updated',
        'payment_recorded' => 'payment.recorded',
        'coupon_created' => 'coupon.created',
        'coupon_updated' => 'coupon.updated',
        'coupon_deleted' => 'coupon.deleted',
        'applied_coupon_created' => 'applied_coupon.created',
        'applied_coupon_deleted' => 'applied_coupon.deleted',
        'payment_request_created' => 'payment_request.created',
        'email_sent' => 'email.sent',
        'feature_created' => 'feature.created',
        'feature_deleted' => 'feature.deleted',
        'feature_updated' => 'feature.updated',
        'product_category_created' => 'product_category.created',
        'product_category_updated' => 'product_category.updated',
        'product_category_deleted' => 'product_category.deleted',
        'product_created' => 'product.created',
        'product_updated' => 'product.updated',
        'product_deleted' => 'product.deleted',
        'product_filter_created' => 'product_filter.created',
        'product_filter_updated' => 'product_filter.updated',
        'product_filter_deleted' => 'product_filter.deleted',
        'rate_card_created' => 'rate_card.created',
        'rate_card_updated' => 'rate_card.updated',
        'rate_card_deleted' => 'rate_card.deleted',
        'quote_created' => 'quote.created',
        'quote_updated' => 'quote.updated',
        'quote_approved' => 'quote.approved',
        'quote_voided' => 'quote.voided',
        'quote_version_created' => 'quote.version_created',
        'order_form_created' => 'order_form.created',
        'order_form_signed' => 'order_form.signed',
        'order_form_file_uploaded' => 'order_form.file_uploaded',
        'order_form_expired' => 'order_form.expired',
        'order_form_voided' => 'order_form.voided',
        'order_created' => 'order.created',
        'order_executed' => 'order.executed',
    ];

    /** The stored value for a GraphQL enum name (Rails enum `value:`). */
    public static function activityTypeValue(string $name): ?string
    {
        return self::ACTIVITY_TYPES[$name] ?? null;
    }

    /** The GraphQL enum name for a stored value (`str.tr(".", "_")`). */
    public static function activityTypeName(string $value): string
    {
        return str_replace('.', '_', $value);
    }

    /** The stored resource class for a GraphQL enum name. */
    public static function resourceTypeValue(string $name): ?string
    {
        return self::RESOURCE_TYPES[$name] ?? null;
    }

    /** The GraphQL enum name for a stored resource class. */
    public static function resourceTypeName(string $value): ?string
    {
        return array_search($value, self::RESOURCE_TYPES, true) ?: null;
    }

    public function table(): string
    {
        return 'activity_logs';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    protected function conditions(Organization $organization, array $filters): array
    {
        $conditions = ['organization_id = '.$this->quote($organization->id)];

        if (($retention = $this->retentionCondition($organization)) !== null) {
            $conditions[] = $retention;
        }

        $conditions = [...$conditions, ...$this->loggedAtRange($filters['from_date'] ?? null, $filters['to_date'] ?? null)];

        if (! empty($filters['api_key_ids'])) {
            $conditions[] = $this->inList('api_key_id', $filters['api_key_ids']);
        }

        if (! empty($filters['activity_ids'])) {
            $conditions[] = $this->inList('activity_id', $filters['activity_ids']);
        }

        if (! empty($filters['activity_types'])) {
            // The resolver mapped the GraphQL enum names onto the stored values.
            $conditions[] = $this->inList('activity_type', $filters['activity_types']);
        }

        if (! empty($filters['activity_sources'])) {
            $conditions[] = $this->inList('activity_source', $filters['activity_sources']);
        }

        if (! empty($filters['user_emails'])) {
            // Rails: organization.users.where(email: emails).pluck(:id).
            $userIds = User::query()
                ->whereIn('email', (array) $filters['user_emails'])
                ->whereHas('organizations', fn ($query) => $query->where('organizations.id', $organization->id))
                ->pluck('id')
                ->all();

            $conditions[] = $userIds === []
                ? 'user_id IN (\'\')'
                : $this->inList('user_id', $userIds);
        }

        if (! empty($filters['external_customer_id'])) {
            $conditions[] = $this->inList('external_customer_id', [(string) $filters['external_customer_id']]);
        }

        if (! empty($filters['external_subscription_id'])) {
            $conditions[] = $this->inList('external_subscription_id', [(string) $filters['external_subscription_id']]);
        }

        if (! empty($filters['resource_ids'])) {
            $conditions[] = $this->inList('resource_id', $filters['resource_ids']);
        }

        if (! empty($filters['resource_types'])) {
            // The resolver mapped the GraphQL enum names onto the stored classes.
            $conditions[] = $this->inList('resource_type', $filters['resource_types']);
        }

        return $conditions;
    }
}
