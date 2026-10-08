<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use App\Services\ClickHouse\Logs\ActivityLogQuery;
use Illuminate\Support\Facades\Date;

/**
 * Field resolvers for the frozen SDL's `ActivityLog` type — port of Rails'
 * Types::ActivityLogs::Object (app/graphql/types/activity_logs/object.rb).
 *
 * The root is a raw ClickHouse row (assoc array, string columns, Map
 * columns as JSON objects) as returned by
 * App\Services\ClickHouse\Logs\ActivityLogQuery.
 */
class ActivityLog
{
    public function activityId(array $root): string
    {
        return (string) $root['activity_id'];
    }

    /** Rails TODO-commented transform_values JSON.parse with per-value rescue. */
    public function activityObject(array $root): ?array
    {
        return $this->decodeMapValues($root['activity_object'] ?? null);
    }

    /** Rails: transform_values { |v| JSON.parse(v) } — parse failures surface. */
    public function activityObjectChanges(array $root): ?array
    {
        $map = $root['activity_object_changes'] ?? null;

        if (! is_array($map)) {
            return $map === null ? null : (array) $map;
        }

        return array_map(
            static fn (mixed $value): mixed => is_string($value) ? json_decode($value, true) : $value,
            $map,
        );
    }

    /** Stored Enum8 name — matches the SDL enum values verbatim. */
    public function activitySource(array $root): string
    {
        return (string) $root['activity_source'];
    }

    /** Stored "billable_metric.created" → SDL enum name "billable_metric_created". */
    public function activityType(array $root): string
    {
        return ActivityLogQuery::activityTypeName((string) $root['activity_type']);
    }

    /** Rails: object.api_key (belongs_to api_key). */
    public function apiKey(array $root): ?ApiKey
    {
        $id = $root['api_key_id'] ?? null;

        return ($id !== null && $id !== '') ? ApiKey::find($id) : null;
    }

    public function createdAt(array $root): string
    {
        return $this->datetime($root['created_at']);
    }

    public function externalCustomerId(array $root): ?string
    {
        return $this->stringOrNull($root['external_customer_id'] ?? null);
    }

    public function externalSubscriptionId(array $root): ?string
    {
        return $this->stringOrNull($root['external_subscription_id'] ?? null);
    }

    public function loggedAt(array $root): string
    {
        return $this->datetime($root['logged_at']);
    }

    /** Rails: object.organization (belongs_to organization). */
    public function organization(array $root): ?Organization
    {
        return Organization::find($root['organization_id']);
    }

    /**
     * Rails: Clickhouse::ActivityLog#resource — the polymorphic Postgres
     * record (with_discarded for the RESOURCE_TYPES_WITH_DISCARDED classes),
     * scoped to the log's organization. Returns the model; the
     * ActivityLogResourceObject union resolver picks the concrete SDL type.
     */
    public function resource(array $root): ?object
    {
        $resourceType = (string) ($root['resource_type'] ?? '');
        $resourceId = (string) ($root['resource_id'] ?? '');

        if ($resourceType === '' || $resourceId === '') {
            return null;
        }

        $modelClass = $this->modelClass($resourceType);

        if ($modelClass === null) {
            return null;
        }

        $query = $modelClass::query()
            ->where('organization_id', $root['organization_id'])
            ->where('id', $resourceId);

        if (in_array($resourceType, ActivityLogQuery::RESOURCE_TYPES_WITH_DISCARDED, true)) {
            $query->withTrashed();
        }

        return $query->first();
    }

    /** Rails: object.user&.email. */
    public function userEmail(array $root): ?string
    {
        $id = $root['user_id'] ?? null;

        if ($id === null || $id === '') {
            return null;
        }

        return User::find($id)?->email;
    }

    private function datetime(mixed $value): string
    {
        return Date::parse((string) $value)->utc()->toISOString();
    }

    private function stringOrNull(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }

    private function decodeMapValues(mixed $map): ?array
    {
        if (! is_array($map)) {
            return $map === null ? null : (array) $map;
        }

        return array_map(function (mixed $value): mixed {
            if (! is_string($value)) {
                return $value;
            }

            $parsed = json_decode($value, true);

            // Rails: (parsed.is_a?(Array) || parsed.is_a?(Hash)) ? parsed : value.
            if (is_array($parsed)) {
                return $parsed;
            }

            return $value;
        }, $map);
    }

    /** Stored resource class → Laravel model class (::class). */
    private function modelClass(string $resourceType): ?string
    {
        return match ($resourceType) {
            'BillableMetric' => \App\Models\BillableMetric::class,
            'Plan' => \App\Models\Plan::class,
            'CatalogPlan' => \App\Models\CatalogPlan::class,
            'Customer' => \App\Models\Customer::class,
            'Invoice' => \App\Models\Invoice::class,
            'CreditNote' => \App\Models\CreditNote::class,
            'BillingEntity' => \App\Models\BillingEntity::class,
            'Subscription' => \App\Models\Subscription::class,
            'Wallet' => \App\Models\Wallet::class,
            'Coupon' => \App\Models\Coupon::class,
            'PaymentReceipt' => \App\Models\PaymentReceipt::class,
            'PaymentRequest' => \App\Models\PaymentRequest::class,
            'Entitlement::Feature' => \App\Models\Feature::class,
            'ProductCategory' => \App\Models\ProductCategory::class,
            'Product' => \App\Models\Product::class,
            'ProductFilter' => \App\Models\ProductFilter::class,
            'RateCard' => \App\Models\RateCard::class,
            'Quote' => \App\Models\Quote::class,
            'OrderForm' => \App\Models\OrderForm::class,
            'Order' => \App\Models\Order::class,
            default => null,
        };
    }
}
