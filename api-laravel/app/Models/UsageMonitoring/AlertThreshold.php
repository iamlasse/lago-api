<?php

declare(strict_types=1);

namespace App\Models\UsageMonitoring;

use App\Models\BaseModel;
use App\Models\Casts\BcNumeric;
use App\Models\Casts\PostgresArray;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' UsageMonitoring::AlertThreshold
 * (app/models/usage_monitoring/alert_threshold.rb).
 */
#[Table(name: 'usage_monitoring_alert_thresholds')]
#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'organization_id',
    'usage_monitoring_alert_id',
    'code',
    'value',
    'recurring',
    'notify_on',
])]
class AlertThreshold extends BaseModel
{
    use HasFactory;

    /** Rails: SOFT_LIMIT. */
    public const SOFT_LIMIT = 20;

    public const NOTIFY_ON_TRIGGERED = 'triggered';

    public const NOTIFY_ON_RESOLVED = 'resolved';

    /** Rails: NOTIFY_ON_VALUES. */
    public const NOTIFY_ON_VALUES = [self::NOTIFY_ON_TRIGGERED, self::NOTIFY_ON_RESOLVED];

    protected $attributes = [
        'recurring' => false,
        'notify_on' => '{triggered}',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class, 'usage_monitoring_alert_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Organization::class);
    }

    /** Rails: #notify_on_resolved?. */
    public function notifyOnResolved(): bool
    {
        return in_array(self::NOTIFY_ON_RESOLVED, (array) ($this->notify_on ?? []), true);
    }

    protected function casts(): array
    {
        return [
            'value' => BcNumeric::class,
            'recurring' => 'boolean',
            'notify_on' => PostgresArray::class,
        ];
    }
}
