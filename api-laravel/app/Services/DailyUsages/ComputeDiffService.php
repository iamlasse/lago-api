<?php

declare(strict_types=1);

namespace App\Services\DailyUsages;

use App\Models\DailyUsage;
use App\Support\MoneyMath;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' DailyUsages::ComputeDiffService
 * (app/services/daily_usages/compute_diff_service.rb) — subtracts the
 * previous daily-usage snapshot from the current one, so downstream
 * consumers summing `usage_diff` see the day's increment instead of the
 * period-to-date total.
 */
class ComputeDiffService extends BaseService
{
    public function __construct(
        private readonly DailyUsage $dailyUsage,
        private readonly ?DailyUsage $previousDailyUsage = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('usage_diff');

        $usage = $this->dailyUsage->usage ?? [];

        $previousDailyUsage = $this->previousDailyUsage ?? $this->lookupPreviousDailyUsage();

        if ($previousDailyUsage === null) {
            $result->usage_diff = $usage;

            return $result;
        }

        $diff = $this->deepDup($usage);
        $previousUsage = $previousDailyUsage->usage ?? [];

        $previousCharges = $this->chargesUsage($previousUsage);
        $previousChargesIndex = [];
        foreach ($previousCharges as $previousChargeUsage) {
            $previousChargesIndex[$previousChargeUsage['charge']['lago_id']] = $previousChargeUsage;
        }

        $charges = &$this->chargesUsageRef($diff);

        foreach ($charges as $chargeIndex => &$currentChargeUsage) {
            $previousChargeUsage = $previousChargesIndex[$currentChargeUsage['charge']['lago_id']] ?? null;
            if ($previousChargeUsage === null) {
                continue;
            }

            $this->applyDiff($previousChargeUsage, $currentChargeUsage);
            $this->applyFiltersDiff($previousChargeUsage, $currentChargeUsage);
            $this->applyPresentationBreakdownsDiff($previousChargeUsage, $currentChargeUsage);

            $previousGroupedIndex = [];
            foreach ($previousChargeUsage['grouped_usage'] ?? [] as $gu) {
                // Rails index_by { gu["grouped_by"] } — the key is an array,
                // which PHP cannot use raw; hash it.
                $previousGroupedIndex[$this->indexKey($gu['grouped_by'])] = $gu;
            }

            foreach (array_keys($currentChargeUsage['grouped_usage'] ?? []) as $groupedIndex) {
                $currentGroupedUsage = $currentChargeUsage['grouped_usage'][$groupedIndex];
                $previousGroupedUsage = $previousGroupedIndex[$this->indexKey($currentGroupedUsage['grouped_by'])] ?? null;
                if ($previousGroupedUsage === null) {
                    continue;
                }

                $this->applyDiff($previousGroupedUsage, $currentChargeUsage['grouped_usage'][$groupedIndex]);
                $this->applyFiltersDiff($previousGroupedUsage, $currentChargeUsage['grouped_usage'][$groupedIndex]);
                $this->applyPresentationBreakdownsDiff($previousGroupedUsage, $currentChargeUsage['grouped_usage'][$groupedIndex]);
            }
        }
        unset($currentChargeUsage);

        $diff['amount_cents'] = array_sum(array_map(
            fn (array $cu): int => (int) $cu['amount_cents'],
            $this->chargesUsage($diff)
        ));
        $diff['taxes_amount_cents'] -= $this->previousCommonTaxes($diff, $previousUsage, $previousChargesIndex);
        $diff['total_amount_cents'] = $diff['amount_cents'] + $diff['taxes_amount_cents'];

        $result->usage_diff = $diff;

        return $result;
    }

    /**
     * Rails reads `usage["charges_usage"]` — the serialized charges list.
     * The Laravel UsageSerializer wraps the list one level deeper
     * (`charges_usage` => `charges_usage` => [...] — see the serializer's
     * `payload.merge!(charges_usage)` port); both shapes are unwrapped
     * here, and the original wrapper shape is preserved in the diff.
     *
     * @param  array<string, mixed>  $usage
     * @return list<array<string, mixed>>
     */
    private function chargesUsage(array $usage): array
    {
        $chargesUsage = $usage['charges_usage'] ?? [];

        if (isset($chargesUsage['charges_usage']) && is_array($chargesUsage['charges_usage'])) {
            return $chargesUsage['charges_usage'];
        }

        return array_values($chargesUsage);
    }

    /** Reference variant of {@see chargesUsage} for in-place diffing. */
    private function &chargesUsageRef(array &$usage): array
    {
        $chargesUsage = &$usage['charges_usage'];

        if (isset($chargesUsage['charges_usage']) && is_array($chargesUsage['charges_usage'])) {
            return $chargesUsage['charges_usage'];
        }

        return $chargesUsage;
    }

    /**
     * Returns the most recent daily_usage for the same subscription and
     * billing period that is strictly older than the current usage_date.
     *
     * NOTE (ported from Rails): the lookup is intentionally NOT restricted
     * to `usage_date - 1.day`. On days without events, no daily_usage row
     * is saved, so the immediately preceding row may live several days
     * back. Falling back to "full usage" in those cases would double-count
     * the gap days in downstream analytics that sum `usage_diff`.
     */
    private function lookupPreviousDailyUsage(): ?DailyUsage
    {
        return DailyUsage::query()
            ->where('subscription_id', $this->dailyUsage->subscription_id)
            ->where('from_datetime', $this->dailyUsage->from_datetime)
            ->where('to_datetime', $this->dailyUsage->to_datetime)
            ->whereDate('usage_date', '<', $this->dailyUsage->usage_date->toDateString())
            ->orderByDesc('usage_date')
            ->first();
    }

    /**
     * Prorates previous taxes based on how much of the previous amount came
     * from charges that still exist in the current snapshot. This avoids
     * over-deducting taxes when charges are added or removed between
     * snapshots.
     *
     * Example (ported from Rails): previous had charges A(100) + B(200) =
     * 300 with 30 in taxes. Current only has charge A. Common ratio =
     * 100/300 = 1/3, so we deduct 10 (not 30).
     *
     * @param  array<string, mixed>  $diff
     * @param  array<string, mixed>  $previousUsage
     * @param  array<string, array<string, mixed>>  $previousChargesIndex
     */
    private function previousCommonTaxes(array $diff, array $previousUsage, array $previousChargesIndex): int
    {
        if ((int) ($previousUsage['amount_cents'] ?? 0) <= 0) {
            return (int) ($previousUsage['taxes_amount_cents'] ?? 0);
        }

        $previousCommonAmount = array_sum(array_map(
            fn (array $cu): int => (int) ($previousChargesIndex[$cu['charge']['lago_id']]['amount_cents'] ?? 0),
            $this->chargesUsage($diff)
        ));

        $commonRatio = MoneyMath::fdiv((string) $previousCommonAmount, (string) $previousUsage['amount_cents']);

        return MoneyMath::round(MoneyMath::mul((string) $previousUsage['taxes_amount_cents'], $commonRatio));
    }

    /**
     * @param  array<string, mixed>  $previousParent
     * @param  array<string, mixed>  $currentParent
     */
    private function applyFiltersDiff(array $previousParent, array &$currentParent): void
    {
        $previousFiltersIndex = [];
        foreach ($previousParent['filters'] ?? [] as $fu) {
            // Rails index_by { fu["values"] } — the key is an array, which
            // PHP cannot use raw; hash it.
            $previousFiltersIndex[$this->indexKey($fu['values'])] = $fu;
        }

        foreach (($currentParent['filters'] ?? []) as $filterIndex => $currentFilter) {
            $previousFilter = $previousFiltersIndex[$this->indexKey($currentFilter['values'])] ?? null;
            if ($previousFilter === null) {
                continue;
            }

            $this->applyDiff($previousFilter, $currentParent['filters'][$filterIndex]);
            $this->applyPresentationBreakdownsDiff($previousFilter, $currentParent['filters'][$filterIndex]);
        }
    }

    /**
     * @param  array<string, mixed>  $previousParent
     * @param  array<string, mixed>  $currentParent
     */
    private function applyPresentationBreakdownsDiff(array $previousParent, array &$currentParent): void
    {
        $previousIndex = [];
        foreach ((array) ($previousParent['presentation_breakdowns'] ?? []) as $pb) {
            // Rails index_by { pb["presentation_by"] } — the key is an
            // array, which PHP cannot use raw; hash it.
            $previousIndex[$this->indexKey($pb['presentation_by'])] = $pb;
        }

        foreach (($currentParent['presentation_breakdowns'] ?? []) as $breakdownIndex => $currentBreakdown) {
            $previousBreakdown = $previousIndex[$this->indexKey($currentBreakdown['presentation_by'])] ?? null;
            if ($previousBreakdown === null) {
                continue;
            }

            $currentUnits = (string) ($currentBreakdown['units'] ?? '0');
            $previousUnits = (string) ($previousBreakdown['units'] ?? '0');
            $currentParent['presentation_breakdowns'][$breakdownIndex]['units'] = MoneyMath::toF(
                MoneyMath::sub($currentUnits, $previousUnits)
            );
        }
    }

    /**
     * @param  array<string, mixed>  $previousValues
     * @param  array<string, mixed>  $currentValues
     */
    private function applyDiff(array $previousValues, array &$currentValues): void
    {
        $currentValues['units'] = MoneyMath::toF(MoneyMath::sub(
            (string) $currentValues['units'],
            (string) $previousValues['units']
        ));
        $currentValues['events_count'] = (int) $currentValues['events_count'] - (int) $previousValues['events_count'];
        $currentValues['amount_cents'] = (int) $currentValues['amount_cents'] - (int) $previousValues['amount_cents'];
    }

    /** JSON-hashed array key — PHP array keys cannot be arrays (Rails' index_by can). */
    private function indexKey(mixed $value): string
    {
        return is_array($value) ? (string) json_encode($value) : (string) $value;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function deepDup(array $value): array
    {
        return json_decode((string) json_encode($value), true);
    }
}
