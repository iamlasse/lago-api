<?php

declare(strict_types=1);

namespace App\Services\Charges\Validators;

use App\Services\Validators\DecimalAmount;
use App\Services\Charges\AggregationChecks;
use App\Services\Charges\Validators\Concerns\RangeBounds;

/**
 * Port of Rails' Charges::Validators::GraduatedPercentageService.
 */
class GraduatedPercentageService extends BaseService
{
    use RangeBounds;

    public function valid(): bool
    {
        $this->validateBillableMetric();

        $ranges = $this->ranges();

        if ($ranges === []) {
            $this->addError('graduated_percentage_ranges', 'missing_graduated_percentage_ranges');
        } else {
            $nextFromValue = 0;

            foreach ($ranges as $index => $range) {
                $this->validateRateAndAmounts($range);

                if (! $this->validBounds($range, $index, $nextFromValue)) {
                    $this->addError('graduated_percentage_ranges', 'invalid_graduated_percentage_ranges');
                }

                $nextFromValue = (int) ($range['to_value'] ?? 0);
            }
        }

        return parent::valid();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function ranges(): array
    {
        return array_map(
            fn ($range) => (array) $range,
            $this->properties['graduated_percentage_ranges'] ?? [],
        );
    }

    /**
     * @param  array<string, mixed>  $range
     */
    protected function validateRateAndAmounts(array $range): void
    {
        if (! DecimalAmount::validAmount($range['flat_amount'] ?? null)) {
            $this->addError('flat_amount', 'invalid_amount');
        }

        if (! DecimalAmount::validAmount($range['rate'] ?? null)) {
            $this->addError('rate', 'invalid_rate');
        }
    }

    private function validateBillableMetric(): void
    {
        $billableMetric = $this->charge instanceof \App\Models\Charge
            ? $this->charge->billableMetric
            : null;

        if (! AggregationChecks::isLatest($billableMetric)) {
            $this->addError('billable_metric', 'invalid_value');
        }
    }
}
