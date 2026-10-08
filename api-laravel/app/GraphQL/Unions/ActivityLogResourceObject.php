<?php

declare(strict_types=1);

namespace App\GraphQL\Unions;

use App\GraphQL\Interfaces\AbstractLagoTypeResolver;

/**
 * Type resolver for the frozen SDL's `union ActivityLogResourceObject` —
 * port of Rails' Types::ActivityLogs::ResourceObject#resolve_type
 * (app/graphql/types/activity_logs/resource_object.rb): the Postgres model
 * class of the activity log's polymorphic resource maps to its SDL type.
 */
class ActivityLogResourceObject extends AbstractLagoTypeResolver
{
    public function __invoke(mixed $root): \GraphQL\Type\Definition\Type
    {
        $class = $root instanceof \Illuminate\Database\Eloquent\Model
            ? $root::class
            : null;

        $name = match ($class) {
            \App\Models\BillableMetric::class => 'BillableMetric',
            \App\Models\Plan::class => 'Plan',
            \App\Models\CatalogPlan::class => 'CatalogPlan',
            \App\Models\Customer::class => 'Customer',
            \App\Models\Invoice::class => 'Invoice',
            \App\Models\CreditNote::class => 'CreditNote',
            \App\Models\BillingEntity::class => 'BillingEntity',
            \App\Models\Subscription::class => 'Subscription',
            \App\Models\Wallet::class => 'Wallet',
            \App\Models\Coupon::class => 'Coupon',
            \App\Models\PaymentRequest::class => 'PaymentRequest',
            \App\Models\PaymentReceipt::class => 'PaymentReceipt',
            \App\Models\Feature::class => 'FeatureObject',
            \App\Models\ProductCategory::class => 'ProductCategory',
            \App\Models\Product::class => 'Product',
            \App\Models\ProductFilter::class => 'ProductFilter',
            \App\Models\RateCard::class => 'RateCard',
            \App\Models\Quote::class => 'Quote',
            \App\Models\OrderForm::class => 'OrderForm',
            \App\Models\Order::class => 'Order',
            default => null,
        };

        if ($name === null) {
            // Rails: raise "Unexpected activity log resource type: ...".
            throw \App\GraphQL\Execution\Errors::executionError(
                error: 'Unexpected activity log resource type',
                status: 500,
                code: 'internal_error',
            );
        }

        return $this->type($name);
    }
}
