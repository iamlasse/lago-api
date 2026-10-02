<?php

declare(strict_types=1);

namespace App\Services\Fees\ChargeService;

use InvalidArgumentException;

/**
 * Port of Rails' Fees::ChargeService::Options
 * (app/services/fees/charge_service/options.rb).
 *
 * Not ported: UsageFilters (usage filtering by group / presentation arrives
 * with the M2 event store).
 */
final class Options
{
    public const CONTEXTS = [null, 'current_usage', 'invoice_preview', 'recurring', 'finalize'];

    /** NOTE: Accepted for callers that still use invoice-level contexts. */
    public const DEPRECATED_CONTEXTS = ['refresh', 'draft'];

    public function __construct(
        public readonly ?string $context = null,
        public readonly bool $applyTaxes = false,
        public readonly bool $calculateProjectedUsage = false,
        public readonly bool $withZeroUnitsFilters = true,
        public readonly bool $skipAdjustedFees = false,
    ) {
        $accepted = [...self::CONTEXTS, ...self::DEPRECATED_CONTEXTS];

        if (! in_array($context, $accepted, true)) {
            throw new InvalidArgumentException(
                "context '".($context ?? 'null')."' must be one of: ".implode(', ', array_filter($accepted)),
            );
        }
    }

    public static function default(): self
    {
        return new self;
    }

    public function currentUsage(): bool
    {
        return $this->context === 'current_usage';
    }

    public function invoicePreview(): bool
    {
        return $this->context === 'invoice_preview';
    }

    public function recurring(): bool
    {
        return $this->context === 'recurring';
    }
}
