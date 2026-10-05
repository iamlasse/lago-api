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
use App\Services\QuoteVersions\Validators\Validators;
use App\Services\OrderForms\CreateService as OrderFormCreateService;

/**
 * Port of Rails' QuoteVersions::ApproveService
 * (app/services/quote_versions/approve_service.rb) — freezes the deal: the
 * mention variables are snapshotted and the order form is generated in the
 * same locked transaction.
 */
class ApproveService extends BaseService
{
    public function __construct(
        private readonly ?QuoteVersion $quoteVersion,
        private readonly mixed $expiresAt = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('quote_version', 'order_form');
        $quoteVersion = $this->quoteVersion;

        if ($quoteVersion === null) {
            return $result->notFoundFailure('quote_version');
        }

        if (! $this->orderFormsEnabled($quoteVersion->organization)) {
            return $result->forbiddenFailure();
        }

        try {
            DB::transaction(function () use ($quoteVersion, $result): void {
                LockService::call($quoteVersion->quote, function () use ($quoteVersion, $result): void {
                    $quoteVersion->refresh();

                    if (! $quoteVersion->isDraft()) {
                        $result->singleValidationFailure('not_approvable', 'status');

                        return;
                    }

                    $validator = Validators::for($result, $quoteVersion, 'approve');

                    if ($validator !== null && ! $validator->valid()) {
                        return;
                    }

                    $mentionVariables = ComputeMentionVariablesService::callBang(
                        quoteVersion: $quoteVersion,
                    )->mention_variables;

                    $quoteVersion->status = 'approved';
                    $quoteVersion->approved_at = now();
                    $quoteVersion->mention_variables = $mentionVariables;
                    $quoteVersion->save();

                    $orderForm = OrderFormCreateService::callBang(
                        quoteVersion: $quoteVersion,
                        expiresAt: $this->expiresAt,
                    )->order_form;

                    $result->order_form = $orderForm;
                    $result->quote_version = $quoteVersion;
                });
            });
        } catch (LockAcquisitionFailure) {
            return $result->singleValidationFailure('concurrency_conflict', 'base');
        }

        return $result;
    }

    /** Rails: OrderForms::Premium#order_forms_enabled?. */
    protected function orderFormsEnabled(object $organization): bool
    {
        return License::premium()
            && in_array('order_forms', (array) ($organization->feature_flags ?? []), true);
    }
}
