<?php

declare(strict_types=1);

namespace App\Models\UsageMonitoring;

use App\Models\Wallet;
use App\Models\BaseModel;
use App\Models\Subscription;
use App\Models\Casts\BcNumeric;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' UsageMonitoring::TriggeredAlert
 * (app/models/usage_monitoring/triggered_alert.rb).
 */
#[Table(name: 'usage_monitoring_triggered_alerts')]
#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'organization_id',
    'usage_monitoring_alert_id',
    'subscription_id',
    'wallet_id',
    'kind',
    'current_value',
    'previous_value',
    'crossed_thresholds',
    'in_alarm_thresholds',
    'fully_resolved',
    'triggered_at',
])]
class TriggeredAlert extends BaseModel
{
    use HasFactory;

    /** Rails: KINDS. */
    public const KINDS = [
        'triggered' => 'triggered',
        'resolved' => 'resolved',
        'seeded' => 'seeded',
    ];

    protected $attributes = [
        'kind' => 'triggered',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Organization::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** Rails: belongs_to :alert, -> { with_discarded }. */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class, 'usage_monitoring_alert_id')->withTrashed();
    }

    protected function casts(): array
    {
        return [
            'current_value' => BcNumeric::class,
            'previous_value' => BcNumeric::class,
            'crossed_thresholds' => 'array',
            'in_alarm_thresholds' => 'array',
            'fully_resolved' => 'boolean',
            'kind' => 'string',
            'triggered_at' => 'datetime',
        ];
    }
}
