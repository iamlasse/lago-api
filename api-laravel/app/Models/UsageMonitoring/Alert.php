<?php

declare(strict_types=1);

namespace App\Models\UsageMonitoring;

use LogicException;
use App\Models\Wallet;
use App\Models\BaseModel;
use App\Support\MoneyMath;
use App\Models\BillableMetric;
use App\Models\Casts\BcNumeric;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' UsageMonitoring::Alert (app/models/usage_monitoring/alert.rb).
 *
 * Rails uses STI on alert_type with a subclass per type; the find_value
 * bodies differ per subclass only, so the port keeps ONE model and dispatches
 * findValue() on the alert_type column (the STI_MAPPING table below is the
 * Rails mapping, used for validation parity). The `default_scope -> { kept }`
 * discard behaviour maps to Laravel SoftDeletes on deleted_at.
 *
 * Not ported (TODO(port)): PaperTrail trace.
 */
#[Table(name: 'usage_monitoring_alerts')]
#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'organization_id',
    'subscription_external_id',
    'billable_metric_id',
    'wallet_id',
    'alert_type',
    'previous_value',
    'last_processed_at',
    'name',
    'code',
    'direction',
])]
class Alert extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    /** Rails: STI_MAPPING — every valid alert_type and its Rails STI class. */
    public const STI_MAPPING = [
        'current_usage_amount' => 'UsageMonitoring::CurrentUsageAmountAlert',
        'billable_metric_current_usage_amount' => 'UsageMonitoring::BillableMetricCurrentUsageAmountAlert',
        'billable_metric_current_usage_units' => 'UsageMonitoring::BillableMetricCurrentUsageUnitsAlert',
        'lifetime_usage_amount' => 'UsageMonitoring::LifetimeUsageAmountAlert',
        'billable_metric_lifetime_usage_units' => 'UsageMonitoring::BillableMetricLifetimeUsageUnitsAlert',
        'wallet_balance_amount' => 'UsageMonitoring::WalletBalanceAmountAlert',
        'wallet_credits_balance' => 'UsageMonitoring::WalletCreditsBalanceAlert',
        'wallet_ongoing_balance_amount' => 'UsageMonitoring::WalletOngoingBalanceAmountAlert',
        'wallet_credits_ongoing_balance' => 'UsageMonitoring::WalletCreditsOngoingBalanceAlert',
    ];

    /** Rails: CURRENT_USAGE_TYPES. */
    public const CURRENT_USAGE_TYPES = [
        'current_usage_amount',
        'billable_metric_current_usage_amount',
        'billable_metric_current_usage_units',
    ];

    /** Rails: BILLABLE_METRIC_TYPES. */
    public const BILLABLE_METRIC_TYPES = [
        'billable_metric_current_usage_amount',
        'billable_metric_current_usage_units',
        'billable_metric_lifetime_usage_units',
    ];

    /** Rails: BILLABLE_METRIC_LIFETIME_USAGE_TYPES. */
    public const BILLABLE_METRIC_LIFETIME_USAGE_TYPES = [
        'billable_metric_lifetime_usage_units',
    ];

    /** Rails: SUBSCRIPTION_TYPES. */
    public const SUBSCRIPTION_TYPES = [
        'current_usage_amount',
        'billable_metric_current_usage_amount',
        'billable_metric_current_usage_units',
        'lifetime_usage_amount',
        'billable_metric_lifetime_usage_units',
    ];

    /** Rails: WALLET_TYPES. */
    public const WALLET_TYPES = [
        'wallet_balance_amount',
        'wallet_credits_balance',
        'wallet_ongoing_balance_amount',
        'wallet_credits_ongoing_balance',
    ];

    /** Rails: DIRECTIONS. */
    public const DIRECTIONS = [
        'increasing' => 'increasing',
        'decreasing' => 'decreasing',
    ];

    protected $attributes = [
        'previous_value' => '0.0',
        'direction' => 'increasing',
    ];

    private ?AlertThreshold $recurringThresholdMemo = null;

    /** @var list<string>|null */
    private ?array $oneTimeThresholdsValuesMemo = null;

    // -- Rails scopes ------------------------------------------------------------

    /** Rails: scope :using_current_usage. */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function usingCurrentUsage(Builder $query): void
    {
        $query->whereIn('alert_type', self::CURRENT_USAGE_TYPES);
    }

    /** Rails: scope :using_lifetime_usage. */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function usingLifetimeUsage(Builder $query): void
    {
        $query->where('alert_type', 'lifetime_usage_amount');
    }

    /** Rails: scope :using_billable_metric_lifetime_usage. */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function usingBillableMetricLifetimeUsage(Builder $query): void
    {
        $query->whereIn('alert_type', self::BILLABLE_METRIC_LIFETIME_USAGE_TYPES);
    }

    /** Rails: scope :using_subscription. */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function usingSubscription(Builder $query): void
    {
        $query->whereIn('alert_type', self::SUBSCRIPTION_TYPES);
    }

    /** Rails: scope :using_wallet. */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function usingWallet(Builder $query): void
    {
        $query->whereIn('alert_type', self::WALLET_TYPES);
    }

    // -- Relations ---------------------------------------------------------------

    public function organization(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Organization::class);
    }

    /** Rails: belongs_to :billable_metric, -> { with_discarded }, optional: true. */
    public function billableMetric(): BelongsTo
    {
        return $this->belongsTo(BillableMetric::class)->withTrashed();
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** Rails: has_many :thresholds, foreign_key: :usage_monitoring_alert_id, dependent: :delete_all. */
    public function thresholds(): HasMany
    {
        return $this->hasMany(AlertThreshold::class, 'usage_monitoring_alert_id');
    }

    /** Rails: has_many :triggered_alerts, -> { triggered }. */
    public function triggeredAlerts(): HasMany
    {
        return $this->hasMany(TriggeredAlert::class, 'usage_monitoring_alert_id')->where('kind', 'triggered');
    }

    /** Rails: has_many :all_triggered_alerts. */
    public function allTriggeredAlerts(): HasMany
    {
        return $this->hasMany(TriggeredAlert::class, 'usage_monitoring_alert_id');
    }

    // -- Threshold crossing (ported verbatim from the Rails Alert model) ---------

    /**
     * Rails: #find_thresholds_crossed — the threshold values (decimal strings)
     * crossed moving from previous_value to the given current value, ascending.
     *
     * @return list<string>
     */
    public function findThresholdsCrossed(string|int|float $current): array
    {
        $crossed = $this->increasing()
            ? $this->findThresholdsCrossedIncreasing($current)
            : $this->findThresholdsCrossedDecreasing($current);

        // Rails' BigDecimal#to_s("F") form ("100.0").
        return array_map(fn (string $v): string => MoneyMath::toF($v), $crossed);
    }

    /** @return list<string> */
    public function findThresholdsCrossedIncreasing(string|int|float $current): array
    {
        $crossed = [];
        $previous = (string) $this->previous_value;
        $current = MoneyMath::toDecimalString($current);

        if (MoneyMath::compare($current, $previous) <= 0) {
            return $crossed;
        }

        $oneTime = $this->oneTimeThresholdsValues();

        if ($oneTime !== []) {
            if (MoneyMath::compare($current, $oneTime[0]) < 0) {
                return $crossed;
            }

            if (MoneyMath::compare($previous, $oneTime[count($oneTime) - 1]) < 0) {
                foreach ($oneTime as $value) {
                    if (MoneyMath::compare($value, $previous) > 0 && MoneyMath::compare($value, $current) <= 0) {
                        $crossed[] = $value;
                    }
                }
            }
        }

        $initial = $oneTime !== [] ? $oneTime[count($oneTime) - 1] : '0';
        $recurring = $this->recurringThreshold();

        $crossed = array_merge(
            $crossed,
            $this->findRecurringThresholdsCrossedIncreasing($previous, $current, $recurring?->value, $initial),
        );

        $crossed = array_values(array_unique($crossed));
        usort($crossed, fn (string $a, string $b): int => MoneyMath::compare($a, $b));

        return $crossed;
    }

    /** @return list<string> */
    public function findThresholdsCrossedDecreasing(string|int|float $current): array
    {
        $crossed = [];
        $previous = (string) $this->previous_value;
        $current = MoneyMath::toDecimalString($current);

        if (MoneyMath::compare($current, $previous) >= 0) {
            return $crossed;
        }

        $oneTime = $this->oneTimeThresholdsValues();

        if ($oneTime !== []) {
            if (MoneyMath::compare($current, $oneTime[count($oneTime) - 1]) > 0) {
                return $crossed;
            }

            if (MoneyMath::compare($previous, $oneTime[0]) > 0) {
                foreach ($oneTime as $value) {
                    if (MoneyMath::compare($value, $previous) < 0 && MoneyMath::compare($value, $current) >= 0) {
                        $crossed[] = $value;
                    }
                }
            }
        }

        $initial = $oneTime !== [] ? $oneTime[0] : '0';
        $recurring = $this->recurringThreshold();

        $crossed = array_merge(
            $crossed,
            $this->findRecurringThresholdsCrossedDecreasing($previous, $current, $recurring?->value, $initial),
        );

        $crossed = array_values(array_unique($crossed));
        usort($crossed, fn (string $a, string $b): int => MoneyMath::compare($a, $b));

        return $crossed;
    }

    /** Rails: #recurring_threshold — memoized on the instance. */
    public function recurringThreshold(): ?AlertThreshold
    {
        if ($this->recurringThresholdMemo === null) {
            $this->recurringThresholdMemo = $this->thresholds->first(fn (AlertThreshold $t): bool => $t->recurring);
        }

        return $this->recurringThresholdMemo;
    }

    /** Rails: #one_time_thresholds_values — uniq.sort of non-recurring values. */
    public function oneTimeThresholdsValues(): array
    {
        if ($this->oneTimeThresholdsValuesMemo === null) {
            $values = $this->thresholds
                ->filter(fn (AlertThreshold $t): bool => ! $t->recurring)
                ->map(fn (AlertThreshold $t): string => MoneyMath::toF((string) $t->value))
                ->unique()
                ->values()
                ->all();

            usort($values, fn (string $a, string $b): int => MoneyMath::compare($a, $b));

            $this->oneTimeThresholdsValuesMemo = $values;
        }

        return $this->oneTimeThresholdsValuesMemo;
    }

    /**
     * Rails: #formatted_crossed_thresholds — the webhook payload for the
     * crossed values, regular thresholds first then recurring ones.
     *
     * @param  list<string>  $crossedThresholdValues
     * @return list<array{code: ?string, value: string, recurring: bool}>
     */
    public function formattedCrossedThresholds(array $crossedThresholdValues): array
    {
        $oneTime = $this->oneTimeThresholdsValues();

        [$regularValues, $recurringValues] = collect($crossedThresholdValues)
            ->partition(fn (string $v): bool => in_array($v, $oneTime, true));

        $formattedRegular = $this->thresholds
            ->reject(fn (AlertThreshold $t): bool => (bool) $t->recurring)
            ->filter(fn (AlertThreshold $t): bool => $regularValues->contains((string) $t->value))
            ->map(fn (AlertThreshold $t): array => [
                'code' => $t->code,
                'value' => MoneyMath::toF((string) $t->value),
                'recurring' => false,
            ])
            ->values()
            ->all();

        $recurring = $this->recurringThreshold();

        $formattedRecurring = collect($recurringValues)
            ->map(fn (string $v): array => [
                'code' => $recurring?->code,
                'value' => MoneyMath::toF($v),
                'recurring' => true,
            ])
            ->all();

        return array_merge($formattedRegular, $formattedRecurring);
    }

    /**
     * Rails: #find_value — dispatched on alert_type (Rails' STI subclasses'
     * #find_value bodies). `$currentMetrics` is a Wallet for wallet types,
     * a current-usage hash/object for current-usage types and a LifetimeUsage
     * for lifetime_usage_amount.
     */
    public function findValue(mixed $currentMetrics): string
    {
        $value = match ($this->alert_type) {
            // UsageMonitoring::CurrentUsageAmountAlert#find_value
            'current_usage_amount' => $this->metric($currentMetrics, 'amount_cents', 'amountCents'),
            // UsageMonitoring::LifetimeUsageAmountAlert#find_value
            'lifetime_usage_amount' => $currentMetrics->totalAmountCents(),
            // UsageMonitoring::WalletBalanceAmountAlert#find_value
            'wallet_balance_amount' => $currentMetrics->balance_cents,
            // UsageMonitoring::WalletOngoingBalanceAmountAlert#find_value
            'wallet_ongoing_balance_amount' => $currentMetrics->ongoing_balance_cents,
            // UsageMonitoring::WalletCreditsBalanceAlert#find_value
            'wallet_credits_balance' => $currentMetrics->credits_balance,
            // UsageMonitoring::WalletCreditsOngoingBalanceAlert#find_value
            'wallet_credits_ongoing_balance' => $currentMetrics->credits_ongoing_balance,
            'billable_metric_current_usage_amount',
            'billable_metric_current_usage_units',
            'billable_metric_lifetime_usage_units' => $this->findBillableMetricValue($currentMetrics),
            default => throw new LogicException("Unknown alert_type '{$this->alert_type}'"),
        };

        return MoneyMath::toDecimalString($value ?? 0);
    }

    public function increasing(): bool
    {
        return $this->direction === 'increasing';
    }

    public function decreasing(): bool
    {
        return $this->direction === 'decreasing';
    }

    /** Rails: need_billable_metric? / need_wallet? / need_subscription?. */
    public function needBillableMetric(): bool
    {
        return in_array($this->alert_type, self::BILLABLE_METRIC_TYPES, true);
    }

    public function needWallet(): bool
    {
        return in_array($this->alert_type, self::WALLET_TYPES, true);
    }

    public function needSubscription(): bool
    {
        return ! $this->needWallet();
    }

    /**
     * Port of the Rails model validations — `field => [api error codes]`,
     * empty when valid.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        if (($this->alert_type ?? '') === '') {
            $errors['alert_type'] = ['value_is_mandatory'];
        } elseif (! array_key_exists($this->alert_type, self::STI_MAPPING)) {
            $errors['alert_type'] = ['value_is_invalid'];
        }

        if (($this->code ?? '') === '') {
            $errors['code'] = ['value_is_mandatory'];
        }

        if ($this->needBillableMetric() && $this->billable_metric_id === null) {
            $errors['billable_metric'] = ['value_is_mandatory'];
        }

        if (! $this->needBillableMetric() && $this->billable_metric_id !== null) {
            $errors['billable_metric'] = ['cannot_be_set_for_this_alert_type'];
        }

        if ($this->needSubscription() && ($this->subscription_external_id ?? '') === '') {
            $errors['subscription_external_id'] = ['value_is_mandatory'];
        }

        if ($this->needWallet() && $this->wallet_id === null) {
            $errors['wallet'] = ['value_is_mandatory'];
        }

        if (! in_array($this->direction, self::DIRECTIONS, true)) {
            $errors['direction'] = ['value_is_invalid'];
        }

        return $errors;
    }

    protected static function booted(): void
    {
        // Rails' `enum :direction, DIRECTIONS, validate: true` — the column is a
        // Postgres enum; an out-of-list value fails at the DB layer.
    }

    protected function casts(): array
    {
        return [
            'previous_value' => BcNumeric::class,
            'direction' => 'string',
            'alert_type' => 'string',
        ];
    }

    /** Reads a metric off an array payload or an object (snake or camel case). */
    private function metric(mixed $metrics, string $snake, string $camel): mixed
    {
        if (is_array($metrics)) {
            return $metrics[$snake] ?? $metrics[$camel] ?? null;
        }

        return $metrics->{$snake} ?? $metrics->{$camel} ?? null;
    }

    /**
     * The BillableMetric*Alert subclasses: the max fee amount/units across the
     * current usage's fees whose charge bills this alert's billable metric.
     */
    private function findBillableMetricValue(mixed $currentUsage): string
    {
        $fees = $this->metric($currentUsage, 'fees', 'fees') ?? [];
        $units = $this->alert_type !== 'billable_metric_current_usage_amount';

        $matching = collect($fees)
            ->filter(function ($fee): bool {
                $chargeId = is_array($fee) ? ($fee['charge_id'] ?? null) : $fee->charge_id;

                if ($chargeId === null) {
                    return false;
                }

                return \App\Models\Charge::query()
                    ->where('id', $chargeId)
                    ->where('billable_metric_id', $this->billable_metric_id)
                    ->exists();
            });

        $max = $matching->map(function ($fee) use ($units): string {
            $raw = is_array($fee)
                ? ($units ? ($fee['units'] ?? 0) : ($fee['amount_cents'] ?? 0))
                : ($units ? ($fee->units ?? 0) : ($fee->amount_cents ?? 0));

            return MoneyMath::toDecimalString($raw ?? 0);
        })->max();

        return $max === null ? '0' : $max;
    }

    /** @return list<string> */
    private function findRecurringThresholdsCrossedIncreasing(
        string $previous,
        string $current,
        ?string $step,
        string $initial,
    ): array {
        if ($step === null) {
            return [];
        }

        $previousSteps = (int) ceil(MoneyMath::compare(MoneyMath::sub($previous, $initial), '0') === 0
            ? 0
            : (float) MoneyMath::fdiv(MoneyMath::sub($previous, $initial), $step));

        $previousRecurring = MoneyMath::add($initial, MoneyMath::mul((string) max($previousSteps, 1), $step));

        $currentSteps = (int) floor((float) MoneyMath::fdiv(MoneyMath::sub($current, $initial), $step));
        $currentRecurring = MoneyMath::add($initial, MoneyMath::mul((string) $currentSteps, $step));

        // Shouldn't happen
        if (MoneyMath::compare($previousRecurring, $currentRecurring) > 0) {
            return [];
        }

        $out = [];
        for ($v = $previousRecurring; MoneyMath::compare($v, $currentRecurring) <= 0; $v = MoneyMath::add($v, $step)) {
            $out[] = $v;
        }

        return $out;
    }

    /** @return list<string> */
    private function findRecurringThresholdsCrossedDecreasing(
        string $previous,
        string $current,
        ?string $step,
        string $initial,
    ): array {
        if ($step === null) {
            return [];
        }

        $previousSteps = (int) ceil(
            (float) MoneyMath::fdiv(MoneyMath::sub($initial, $previous), $step),
        );
        $previousRecurring = MoneyMath::sub($initial, MoneyMath::mul((string) max($previousSteps, 1), $step));

        $currentSteps = (int) floor((float) MoneyMath::fdiv(MoneyMath::sub($initial, $current), $step));
        $currentRecurring = MoneyMath::sub($initial, MoneyMath::mul((string) $currentSteps, $step));

        // Shouldn't happen
        if (MoneyMath::compare($previousRecurring, $currentRecurring) < 0) {
            return [];
        }

        $out = [];
        for ($v = $currentRecurring; MoneyMath::compare($v, $previousRecurring) <= 0; $v = MoneyMath::add($v, $step)) {
            $out[] = $v;
        }

        return $out;
    }
}
