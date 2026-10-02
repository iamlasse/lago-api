<?php

declare(strict_types=1);

namespace App\Services\Charges\Validators;

/**
 * Port of Rails' Charges::Validators::VolumeService — same range checks as
 * graduated, but each range starts one unit after the previous one ends
 * (next_from_value = to_value + 1).
 */
class VolumeService extends GraduatedService
{
    public function valid(): bool
    {
        $ranges = $this->ranges();

        if ($ranges === []) {
            $this->addError('volume_ranges', 'missing_volume_ranges');
        } else {
            $nextFromValue = 0;

            foreach ($ranges as $index => $range) {
                $this->validateAmounts($range);

                if (! $this->validBounds($range, $index, $nextFromValue)) {
                    $this->addError('volume_ranges', 'invalid_volume_ranges');
                }

                $nextFromValue = $this->nextFromValue($range);
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
            $this->properties['volume_ranges'] ?? [],
        );
    }

    /**
     * @param  array<string, mixed>  $range
     */
    protected function nextFromValue(array $range): int
    {
        return ((int) ($range['to_value'] ?? 0)) + 1;
    }
}
