<?php

declare(strict_types=1);

namespace App\Services\Wallets;

use App\Enums\FeeType;
use App\Services\BaseResult;

use function array_map;
use function array_diff;

/**
 * Port of Rails' Wallets::ValidateLimitationsService
 * (app/services/wallets/validate_limitations_service.rb).
 *
 * The billable-metrics check reads result.billable_metrics /
 * result.billable_metric_identifiers, which the caller (Create / Update
 * service) assigns before running the validation — same as Rails.
 */
class ValidateLimitationsService extends BaseValidator
{
    public function __construct(
        BaseResult $result,
        protected array $args,
    ) {
        parent::__construct($result);
    }

    public function valid(): bool
    {
        if (($this->args['applies_to'] ?? null) === null) {
            return true;
        }

        $this->validAllowedFeeTypes();
        $this->validBillableMetrics();

        if ($this->errors()) {
            $this->result->validationFailure($this->messages());

            return false;
        }

        return true;
    }

    /** Counts collections and arrays alike ((array) on a Collection wraps `items`). */
    private static function countOf(mixed $items): int
    {
        if ($items === null) {
            return 0;
        }

        if (is_countable($items)) {
            return count($items);
        }

        return count((array) $items);
    }

    private function validAllowedFeeTypes(): bool
    {
        $feeTypes = $this->args['applies_to']['fee_types'] ?? null;

        if ($feeTypes === null || $feeTypes === [] || $feeTypes === '') {
            return true;
        }

        $validTypes = FeeType::options();
        $incoming = array_map('strval', (array) $feeTypes);
        $invalid = array_diff($incoming, $validTypes);

        if ($invalid !== []) {
            return $this->addError('allowed_fee_types', 'invalid_fee_types');
        }

        return true;
    }

    private function validBillableMetrics(): bool
    {
        $billableMetricIds = $this->args['applies_to']['billable_metric_ids'] ?? null;
        $billableMetricCodes = $this->args['applies_to']['billable_metric_codes'] ?? null;

        if (($billableMetricIds === null || $billableMetricIds === []) && ($billableMetricCodes === null || $billableMetricCodes === [])) {
            return true;
        }

        if (self::countOf($this->result->billable_metrics) === self::countOf($this->result->billable_metric_identifiers)) {
            return true;
        }

        return $this->addError('billable_metrics', 'invalid_identifier');
    }
}
