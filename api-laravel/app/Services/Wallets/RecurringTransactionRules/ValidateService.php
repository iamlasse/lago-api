<?php

declare(strict_types=1);

namespace App\Services\Wallets\RecurringTransactionRules;

use App\Services\Validators\Metadata;
use App\Services\Validators\DecimalAmount;
use App\Enums\RecurringTransactionInterval;
use App\Services\Validators\ExpirationDate;

/**
 * Port of Rails' Wallets::RecurringTransactionRules::ValidateService
 * (app/services/wallets/recurring_transaction_rules/validate_service.rb) —
 * the param-shape validator behind wallet create/update. Rails' `#call`
 * answers a plain boolean (no result), so this is a plain validator object
 * like the other boolean validators in the port.
 */
final class ValidateService
{
    /** @param array<string, mixed> $params */
    public function __construct(
        private readonly array $params,
    ) {}

    /** Rails: `Wallets::RecurringTransactionRules::ValidateService.call(params:)`. */
    public static function call(array $params): bool
    {
        return (new self($params))->valid();
    }

    /** Rails: `#call`. */
    public function valid(): bool
    {
        return $this->validTrigger()
            && $this->validMethod()
            && $this->validTargetAboveThreshold()
            && $this->validCredits()
            && $this->validMetadata()
            && $this->validExpirationAt()
            && $this->validGrantsTargetTopUp();
    }

    /** Rails: `trigger` — `params[:trigger]&.to_s`. */
    private function trigger(): ?string
    {
        $trigger = $this->params['trigger'] ?? null;

        return $trigger === null ? null : (string) $trigger;
    }

    /** Rails: `method` — `params[:method]&.to_s`. */
    private function method(): ?string
    {
        $method = $this->params['method'] ?? null;

        return $method === null ? null : (string) $method;
    }

    private function validTrigger(): bool
    {
        return $this->validIntervalTrigger() || $this->validThresholdTrigger();
    }

    private function validIntervalTrigger(): bool
    {
        return $this->trigger() === 'interval'
            && RecurringTransactionInterval::fromOption($this->params['interval'] ?? null) !== null;
    }

    private function validThresholdTrigger(): bool
    {
        return $this->trigger() === 'threshold'
            && DecimalAmount::validAmount($this->params['threshold_credits'] ?? null);
    }

    private function validMethod(): bool
    {
        if ($this->method() === 'target') {
            return DecimalAmount::validAmount($this->params['target_ongoing_balance'] ?? null);
        }

        return true;
    }

    private function validTargetAboveThreshold(): bool
    {
        if ($this->method() !== 'target' || $this->trigger() !== 'threshold') {
            return true;
        }

        $target = $this->params['target_ongoing_balance'] ?? null;
        $threshold = $this->params['threshold_credits'] ?? null;

        if ($target === null || $threshold === null) {
            return true;
        }

        $targetCanonical = DecimalAmount::canonical($target);
        $thresholdCanonical = DecimalAmount::canonical($threshold);

        if ($targetCanonical === null || $thresholdCanonical === null) {
            return false;
        }

        return bccomp($targetCanonical, $thresholdCanonical, 20) >= 0;
    }

    private function validCredits(): bool
    {
        $paid = $this->params['paid_credits'] ?? null;
        $granted = $this->params['granted_credits'] ?? null;

        if ($paid === null && $granted === null) {
            return true;
        }

        return ($paid !== null && DecimalAmount::validAmount($paid))
            || ($granted !== null && DecimalAmount::validAmount($granted));
    }

    private function validMetadata(): bool
    {
        // Rails: `@metadata = metadata || []` — nil is an empty list.
        return (new Metadata($this->params['transaction_metadata'] ?? []))->valid();
    }

    private function validExpirationAt(): bool
    {
        return ExpirationDate::valid($this->params['expiration_at'] ?? null);
    }

    private function validGrantsTargetTopUp(): bool
    {
        if (($this->params['grants_target_top_up'] ?? null) === null) {
            return true;
        }

        return $this->method() === 'target';
    }
}
