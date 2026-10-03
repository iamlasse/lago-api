<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Port of Rails' Integrations::Aggregator::Taxes::Allocation
 * (app/services/integrations/aggregator/taxes/allocation.rb).
 *
 * Rounding each share on its own leaves the shares adding up to something
 * other than the figure the provider priced. The whole difference cannot go
 * on one share: shares of equal weight all round the same way, so it grows
 * with their number and would drive that share negative. Handing out one
 * cent at a time, largest fractional part first, spends the figure exactly
 * and keeps every share within a cent of its own.
 */
final class Allocation
{
    /**
     * @param  list<int|string>  $weights
     * @return list<int>
     */
    public static function call(int|string|null $total, array $weights): array
    {
        $weights = array_values($weights);

        $sumWeight = '0';
        foreach ($weights as $weight) {
            $sumWeight = MoneyMath::add($sumWeight, (string) $weight);
        }

        if ($total === null || (int) $total === 0 || MoneyMath::compare($sumWeight, '0') === 0) {
            return array_fill(0, count($weights), 0);
        }

        $total = (int) $total;

        $exact = self::precise($total, $weights, $sumWeight);
        $allocated = array_map(
            /** @return int PHP's (int) cast truncates toward zero, like Ruby's #truncate. */
            fn (string $value): int => (int) $value,
            $exact,
        );
        $residue = $total - array_sum($allocated);
        $step = $residue < 0 ? -1 : 1;

        // Sort by [-(fractional part) * step, index] — largest fractional
        // part first (smallest when paying a negative residue back), ties
        // broken by the original index like Rails' sort_by.
        $order = [];
        foreach ($exact as $index => $value) {
            $truncated = (string) (int) $value;
            $fraction = MoneyMath::sub($value, $truncated);
            $order[$index] = (float) MoneyMath::mul(MoneyMath::sub('0', $fraction), (string) $step);
        }

        asort($order, SORT_NUMERIC);

        $indexes = array_slice(array_keys($order), 0, abs($residue));
        foreach ($indexes as $index) {
            $allocated[$index] += $step;
        }

        return array_values($allocated);
    }

    /**
     * @param  list<int|string>  $weights
     * @return list<string>
     */
    public static function precise(int $total, array $weights, ?string $totalWeight = null): array
    {
        $weights = array_values($weights);
        $totalWeight ??= (string) array_sum($weights);
        $totalWeight = MoneyMath::add($totalWeight, '0');

        if ($total === 0 || MoneyMath::compare($totalWeight, '0') === 0) {
            return array_fill(0, count($weights), '0');
        }

        return array_map(
            fn (int|string $weight): string => MoneyMath::fdiv(MoneyMath::mul((string) $total, (string) $weight), $totalWeight),
            $weights,
        );
    }
}
