<?php

declare(strict_types=1);

namespace App\Services\Logs;

use App\Models\Membership;
use App\Support\CurrentContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Port of Rails' Utils::ActivityLog (app/services/utils/activity_log.rb) —
 * produces an activity log event to Kafka for ClickHouse consumption
 * (activity_logs queue → materialized view → activity_logs table).
 *
 * Rails `produce(object, activity_type)` / `produce_after_commit(object,
 * activity_type)`; the block form (serialize → run → reload → serialize →
 * diff) is ported via the `$block` closure argument.
 *
 * TODO(port): `object_serialized` uses the Rails V1::XxxSerializer shape
 * (SERIALIZED_INCLUDED_OBJECTS per root name); the Laravel port serializes
 * the model's attributes until the corresponding serializer slices land.
 * TODO(port): `after_commit` (Rails AfterCommitEverywhere) — the Laravel
 * port produces inline; transactional after-commit hooks land with the
 * queue/jobs wiring.
 */
final class ActivityLog
{
    /** Rails: Utils::ActivityLog::IGNORED_FIELDS. */
    private const IGNORED_FIELDS = ['updated_at'];

    /** Rails: Utils::ActivityLog::IGNORED_EXTERNAL_CUSTOMER_ID_CLASSES. */
    private const IGNORED_EXTERNAL_CUSTOMER_ID_CLASSES = [
        'BillableMetric', 'Coupon', 'Plan', 'CatalogPlan', 'BillingEntity',
        'Entitlement::Feature', 'ProductCategory', 'Product', 'ProductFilter', 'RateCard',
    ];

    /** Rails: Utils::ActivityLog.available?. */
    public static function available(): bool
    {
        return (bool) config('lago.clickhouse.enabled')
            && Kafka::configured()
            && (bool) config('lago.kafka.activity_logs_topic');
    }

    /** Rails: `produce_after_commit` — the flag is accepted and documented. */
    public static function produceAfterCommit(?object $object, string $activityType, ?string $activityId = null): void
    {
        self::produce($object, $activityType, activityId: $activityId, afterCommit: true);
    }

    /**
     * Rails: `produce(object, activity_type, activity_id:, after_commit:) { block }`.
     * The closure runs between the before/after serializations; its return
     * value is forwarded (a failed result short-circuits production).
     *
     * @template TReturn
     *
     * @param  (callable(): TReturn)|null  $block
     * @return TReturn|null
     */
    public static function produce(
        ?object $object,
        string $activityType,
        ?string $activityId = null,
        bool $afterCommit = false,
        ?callable $block = null,
    ): mixed {
        if ($object === null && $block !== null) {
            return $block();
        }

        $changes = [];

        if ($block !== null) {
            $beforeAttributes = self::objectSerialized($object);
            $result = $block();

            if (self::isFailedResult($result)) {
                return $result;
            }

            // NOTE: like Rails, unsaved changes are not part of the diff —
            // the model is reloaded before the after-serialization.
            if ($object instanceof Model) {
                $object->refresh();
            }

            $afterAttributes = self::objectSerialized($object);

            foreach ($beforeAttributes as $key => $before) {
                $after = $afterAttributes[$key] ?? null;

                if ($before !== $after) {
                    $changes[$key] = [$before, $after];
                }
            }
        }

        self::produceWithDiff($object, $activityType, $activityId, $changes);

        return $block !== null ? $result : null;
    }

    private static function produceWithDiff(
        object $object,
        string $activityType,
        ?string $activityId,
        array $changes,
    ): void {
        if (! self::available()) {
            return;
        }

        $currentTime = now()->utc()->format('Y-m-d\TH:i:s');
        $organizationId = self::organizationId($object);
        $resource = self::resource($object);

        Kafka::produceAsync(
            topic: (string) config('lago.kafka.activity_logs_topic'),
            payload: json_encode([
                'activity_source' => self::activitySource(),
                'api_key_id' => CurrentContext::$apiKeyId,
                'user_id' => self::userId($organizationId),
                'activity_type' => $activityType,
                'activity_id' => $activityId ?? (string) \Illuminate\Support\Str::uuid(),
                'logged_at' => $currentTime,
                'created_at' => $currentTime,
                'resource_id' => $resource?->getKey(),
                'resource_type' => $resource !== null ? self::resourceType($resource) : null,
                'organization_id' => $organizationId,
                'activity_object' => self::objectSerialized($object),
                'activity_object_changes' => self::objectChanges($activityType, $changes),
                'external_customer_id' => self::externalCustomerId($object),
                'external_subscription_id' => self::externalSubscriptionId($object),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        );
    }

    /** Rails: "front" when CurrentContext.source == "graphql", else source || "system". */
    private static function activitySource(): string
    {
        if (CurrentContext::$source === 'graphql') {
            return 'front';
        }

        return CurrentContext::$source ?? 'system';
    }

    /**
     * Rails: nil when an api key (or no membership) is in context, else the
     * context membership's user (scoped to the log's organization).
     */
    private static function userId(string $organizationId): ?string
    {
        if (CurrentContext::$apiKeyId !== null && CurrentContext::$apiKeyId !== '') {
            return null;
        }

        $membership = CurrentContext::$membership;

        if ($membership === null) {
            return null;
        }

        if ($membership instanceof Membership) {
            return Membership::query()
                ->where('organization_id', $organizationId)
                ->where('id', $membership->id)
                ->value('user_id');
        }

        return null;
    }

    /**
     * TODO(port): the V1 serializer shape — the Laravel port serializes the
     * model attributes until each domain's serializer is ported.
     *
     * @return array<string, mixed>
     */
    private static function objectSerialized(object $object): array
    {
        if (! $object instanceof Model) {
            return [];
        }

        return $object->attributesToArray();
    }

    /** Rails: `changes.except(IGNORED_FIELDS)` unless the activity type is an update. */
    private static function objectChanges(string $activityType, array $changes): array
    {
        if (! str_contains($activityType, 'updated')) {
            return [];
        }

        return collect($changes)->except(self::IGNORED_FIELDS)->all();
    }

    /** Rails: AppliedCoupon → coupon.organization_id, else object.organization_id. */
    private static function organizationId(object $object): string
    {
        if ($object::class === \App\Models\AppliedCoupon::class) {
            return (string) $object->coupon->organization_id;
        }

        return (string) $object->organization_id;
    }

    /**
     * Rails: Payment → payable, AppliedCoupon → coupon, WalletTransaction →
     * wallet, QuoteVersion → quote, else the object itself.
     */
    private static function resource(object $object): ?object
    {
        return match ($object::class) {
            \App\Models\Payment::class => $object->payable,
            \App\Models\AppliedCoupon::class => $object->coupon,
            \App\Models\WalletTransaction::class => $object->wallet,
            \App\Models\QuoteVersion::class => $object->quote,
            default => $object,
        };
    }

    /**
     * The stored resource_type — Rails stores the model class name
     * (resource.class.name, e.g. "Entitlement::Feature"); the Laravel
     * classes are App\Models namespaced, so the stored names are kept.
     */
    private static function resourceType(object $resource): string
    {
        return match ($resource::class) {
            \App\Models\Feature::class => 'Entitlement::Feature',
            default => class_basename($resource),
        };
    }

    private static function externalCustomerId(object $object): ?string
    {
        if (in_array(self::resourceType($object), self::IGNORED_EXTERNAL_CUSTOMER_ID_CLASSES, true)) {
            return null;
        }

        if ($object instanceof \App\Models\Customer) {
            return (string) $object->external_id;
        }

        $customer = $object->customer ?? null;

        return $customer !== null ? (string) $customer->external_id : null;
    }

    private static function externalSubscriptionId(object $object): ?string
    {
        if (! $object instanceof \App\Models\Subscription) {
            return null;
        }

        return (string) $object->external_id;
    }

    /** Rails: `result.failure?` short-circuit — matched structurally. */
    private static function isFailedResult(mixed $result): bool
    {
        return is_object($result) && method_exists($result, 'failure') && $result->failure();
    }
}
