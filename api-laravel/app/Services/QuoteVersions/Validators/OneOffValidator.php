<?php

declare(strict_types=1);

namespace App\Services\QuoteVersions\Validators;

use App\Models\AddOn;
use App\Support\Utils\Datetime;

/**
 * Port of Rails' QuoteVersions::Validators::OneOff::BusinessValidator
 * (app/services/quote_versions/validators/one_off/business_validator.rb) —
 * the add-on snapshot checks for a one-off deal.
 */
class OneOffValidator extends BaseOrderTypeValidator
{
    /** @var list<string>|null */
    protected ?array $knownAddOnIds = null;

    public function businessValid(): bool
    {
        $this->validateCurrency();
        $this->validateBillingEntity();
        $this->validateAddOns();

        if ($this->hasErrors()) {
            return $this->reportErrors();
        }

        return true;
    }

    protected static function schemaClass(): string
    {
        return OneOffSchema::class;
    }

    protected function validateAddOns(): void
    {
        foreach ($this->addOns() as $index => $addOn) {
            $index = (int) $index;

            $this->validateAddOnExistence($addOn, $index);
            $this->validateDatetimes($addOn, $index);
        }
    }

    /** @param  array<string, mixed>  $addOn */
    protected function validateAddOnExistence(array $addOn, int $index): void
    {
        if (! in_array($addOn['id'] ?? null, $this->knownAddOnIds(), true)) {
            $this->addError($this->addOnField($index, 'id'), 'add_on_not_found');
        }
    }

    /** @param  array<string, mixed>  $addOn */
    protected function validateDatetimes(array $addOn, int $index): void
    {
        foreach (['payload', 'overrides'] as $section) {
            $sectionData = is_array($addOn[$section] ?? null) ? $addOn[$section] : [];

            $from = $sectionData['fromDatetime'] ?? null;
            $to = $sectionData['toDatetime'] ?? null;

            if ($from === null && $to === null) {
                continue;
            }

            if ($from === null) {
                $this->addError($this->addOnField($index, "{$section}.fromDatetime"), 'value_is_mandatory');
            } elseif ($to === null) {
                $this->addError($this->addOnField($index, "{$section}.toDatetime"), 'value_is_mandatory');
            } else {
                $fromDate = Datetime::parseIso8601($from);
                $toDate = Datetime::parseIso8601($to);

                if ($fromDate !== null && $toDate !== null && $fromDate->gt($toDate)) {
                    $this->addError($this->addOnField($index, "{$section}.fromDatetime"), 'invalid_date_range');
                }
            }
        }
    }

    /** @return list<array<string, mixed>> */
    protected function addOns(): array
    {
        $items = $this->normalizedBillingItems()['addOns'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    protected function addOnField(int $index, string $suffix): string
    {
        return "billing_items.addOns.{$index}.{$suffix}";
    }

    /** Rails: known_add_on_ids — with_discarded. */
    protected function knownAddOnIds(): array
    {
        if ($this->knownAddOnIds === null) {
            $ids = array_values(array_filter(array_map(
                fn (array $item): mixed => $item['id'] ?? null,
                $this->addOns(),
            )));

            $this->knownAddOnIds = $ids === []
                ? []
                : AddOn::query()
                    ->withTrashed()
                    ->where('organization_id', $this->quoteVersion->organization_id)
                    ->whereIn('add_ons.id', array_map(strval(...), $ids))
                    ->pluck('add_ons.id')
                    ->map(strval(...))
                    ->all();
        }

        return $this->knownAddOnIds;
    }
}
