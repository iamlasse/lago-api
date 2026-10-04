<?php

declare(strict_types=1);

namespace App\Services\Charges\Validators;

use App\Models\Charge;
use App\Support\License;
use App\Models\FixedCharge;
use App\Models\ChargeFilter;
use App\Services\BaseResult;

/**
 * Port of Rails' Charges::Validators::BaseService — validates the
 * charge-model-agnostic pricing_group_keys / presentation_group_keys
 * properties. Every per-charge-model validator extends this and calls
 * parent::valid() at the end.
 */
abstract class BaseService extends BaseValidator
{
    public const ALLOWED_PRESENTATION_GROUP_KEYS_OPTIONS_KEYS = ['display_in_invoice'];

    public const ALLOWED_PRESENTATION_GROUP_KEYS_KEYS = ['value', 'options'];

    public function __construct(
        protected Charge|FixedCharge|ChargeFilter $charge,
        protected mixed $properties = null,
        ?BaseResult $result = null,
    ) {
        $this->properties = $properties ?? $charge->properties;

        parent::__construct($result ?? BaseResult::of());
    }

    public function valid(): bool
    {
        $this->validatePricingGroupKeys();
        $this->validatePresentationGroupKeys();

        if ($this->errors()) {
            $this->result->validationFailure($this->messages());

            return false;
        }

        return true;
    }

    /** Rails: License.premium? (see App\Services\BaseService::premium). */
    protected function premium(): bool
    {
        return License::premium();
    }

    protected function pricingGroupKeys(): mixed
    {
        return $this->properties[$this->groupedKey()] ?? null;
    }

    /** NOTE: keep accepting grouped_by until the end of the deprecation period. */
    protected function groupedKey(): string
    {
        if (($this->properties['pricing_group_keys'] ?? null) !== null) {
            return 'pricing_group_keys';
        }

        return 'grouped_by';
    }

    protected function validatePricingGroupKeys(): void
    {
        $pricingGroupKeys = $this->pricingGroupKeys();

        if ($pricingGroupKeys === null) {
            return;
        }

        if (is_array($pricingGroupKeys)) {
            if ($pricingGroupKeys === []) {
                return;
            }

            $allPresentStrings = array_all(
                $pricingGroupKeys,
                fn ($key) => is_string($key) && $key !== '',
            );

            if ($allPresentStrings) {
                return;
            }
        }

        $this->addError($this->groupedKey(), 'invalid_type');
    }

    protected function validatePresentationGroupKeys(): void
    {
        $rawKeys = $this->properties['presentation_group_keys'] ?? null;

        if ($rawKeys === null || $rawKeys === [] || $rawKeys === '') {
            return;
        }

        $values = [];

        $validPresentationGroupKeys = is_array($rawKeys) && array_all(
            $rawKeys,
            function ($key) use (&$values): bool {
                if (! is_array($key)) {
                    return false;
                }

                $keysValid = array_diff(array_keys($key), self::ALLOWED_PRESENTATION_GROUP_KEYS_KEYS) === [];

                $valueKeyPresent = array_key_exists('value', $key);
                $valueValid = is_string($key['value'] ?? null) && ($key['value'] ?? '') !== '';

                $optionsKeyValid = true;

                if (array_key_exists('options', $key)) {
                    $options = $key['options'];

                    $optionsKeyValid = is_array($options)
                        && array_keys($options) === self::ALLOWED_PRESENTATION_GROUP_KEYS_OPTIONS_KEYS
                        && in_array($options['display_in_invoice'] ?? null, [true, false], true);
                }

                if ($valueValid) {
                    $values[] = $key['value'];
                }

                return $keysValid && $valueKeyPresent && $valueValid && $optionsKeyValid;
            },
        );

        if (! $validPresentationGroupKeys) {
            $this->addError('presentation_group_keys', 'invalid_type');
        }

        if (count($rawKeys) > 2) {
            $this->addError('presentation_group_keys', 'too_many_keys');
        }

        if (count($values) !== count(array_unique($values))) {
            $this->addError('presentation_group_keys', 'value_is_duplicated');
        }
    }
}
