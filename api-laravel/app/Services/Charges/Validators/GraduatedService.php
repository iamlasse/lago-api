<?php

declare(strict_types=1);

namespace App\Services\Charges\Validators;

use App\Services\Validators\DecimalAmount;
use App\Services\Charges\Validators\Concerns\RangeBounds;

/**
 * Port of Rails' Charges::Validators::GraduatedService.
 */
class GraduatedService extends BaseService
{
    use RangeBounds;

    public function valid(): bool
    {
        $ranges = $this->ranges();

        if ($ranges === []) {
            $this->addError('graduated_ranges', 'missing_graduated_ranges');
        } else {
            $nextFromValue = 0;

            foreach ($ranges as $index => $range) {
                $this->validateAmounts($range);

                if (! $this->validBounds($range, $index, $nextFromValue)) {
                    $this->addError('graduated_ranges', 'invalid_graduated_ranges');
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
            $this->properties['graduated_ranges'] ?? [],
        );
    }

    /**
     * @param  array<string, mixed>  $range
     */
    protected function validateAmounts(array $range): void
    {
        if (! DecimalAmount::validAmount($range['per_unit_amount'] ?? null)) {
            $this->addError('per_unit_amount', 'invalid_amount');
        }

        if (! DecimalAmount::validAmount($range['flat_amount'] ?? null)) {
            $this->addError('flat_amount', 'invalid_amount');
        }
    }
}
