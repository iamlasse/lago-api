<?php

declare(strict_types=1);

namespace App\Services\QuoteVersions;

use App\Support\License;
use App\Models\QuoteVersion;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Quotes\LockService;
use App\Services\Failures\LockAcquisitionFailure;

/**
 * Port of Rails' QuoteVersions::VoidService
 * (app/services/quote_versions/void_service.rb).
 *
 * A draft may be voided for any known reason; an approved version only
 * cascades (the order form expired or was voided, or a clone superseded it).
 */
class VoidService extends BaseService
{
    public function __construct(
        private readonly ?QuoteVersion $quoteVersion,
        private readonly ?string $reason,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('quote_version');
        $quoteVersion = $this->quoteVersion;

        if ($quoteVersion === null) {
            return $result->notFoundFailure('quote_version');
        }

        if (! $this->orderFormsEnabled($quoteVersion->organization)) {
            return $result->forbiddenFailure();
        }

        if ($this->reason === null || ! array_key_exists($this->reason, QuoteVersion::VOID_REASONS)) {
            return $result->singleValidationFailure('invalid', 'void_reason');
        }

        try {
            DB::transaction(function () use ($quoteVersion, $result): void {
                LockService::call($quoteVersion->quote, function () use ($quoteVersion, $result): void {
                    $quoteVersion->refresh();

                    if (! $this->voidable($quoteVersion)) {
                        $result->singleValidationFailure('not_voidable', 'status');

                        return;
                    }

                    $quoteVersion->status = 'voided';
                    $quoteVersion->void_reason = $this->reason;
                    $quoteVersion->voided_at = now();
                    $quoteVersion->approved_at = null;
                    $quoteVersion->save();

                    // Rails: SendWebhookJob "quote.voided" +
                    // Utils::ActivityLog — TODO(port): webhooks / activity
                    // logs slices.

                    $result->quote_version = $quoteVersion;
                });
            });
        } catch (LockAcquisitionFailure) {
            return $result->singleValidationFailure('concurrency_conflict', 'base');
        }

        return $result;
    }

    protected function voidable(QuoteVersion $quoteVersion): bool
    {
        if ($quoteVersion->isDraft()) {
            return true;
        }

        return $quoteVersion->isApproved()
            && array_key_exists((string) $this->reason, QuoteVersion::CASCADE_VOID_REASONS);
    }

    /** Rails: OrderForms::Premium#order_forms_enabled?. */
    protected function orderFormsEnabled(object $organization): bool
    {
        return License::premium()
            && in_array('order_forms', (array) ($organization->feature_flags ?? []), true);
    }
}
