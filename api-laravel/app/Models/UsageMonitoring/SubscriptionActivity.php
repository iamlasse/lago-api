<?php

declare(strict_types=1);

namespace App\Models\UsageMonitoring;

use App\Models\BaseModel;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Port of Rails' UsageMonitoring::SubscriptionActivity
 * (app/models/usage_monitoring/subscription_activity.rb) — the dedup queue
 * row that coalesces per-subscription usage processing.
 *
 * The table has no created_at/updated_at (it uses `inserted_at`, defaulting
 * to CURRENT_TIMESTAMP) and a bigint identity id, so Eloquent timestamps are
 * disabled and rows are inserted raw (Rails' insert_all with
 * unique_by: :idx_subscription_unique — a plain INSERT ... ON CONFLICT DO
 * NOTHING against the partial unique index).
 */
#[Table(name: 'usage_monitoring_subscription_activities')]
class SubscriptionActivity extends BaseModel
{
    public $timestamps = false;

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'organization_id',
        'subscription_id',
        'enqueued',
        'enqueued_at',
        'inserted_at',
    ];

    protected $attributes = [
        'enqueued' => false,
    ];

    /**
     * Rails: SubscriptionActivity.insert_all(..., unique_by: :idx_subscription_unique)
     * — conflicts on the partial unique index are skipped.
     */
    public static function insertFor(Subscription $subscription, string $organizationId): void
    {
        static::query()->newModelInstance()->insertOrIgnore([
            'organization_id' => $organizationId,
            'subscription_id' => $subscription->id,
        ]);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Organization::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    protected function casts(): array
    {
        return [
            'enqueued' => 'boolean',
            'inserted_at' => 'datetime',
            'enqueued_at' => 'datetime',
        ];
    }
}
