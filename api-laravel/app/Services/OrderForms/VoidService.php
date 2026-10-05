<?php

declare(strict_types=1);

namespace App\Services\OrderForms;

use App\Support\License;
use App\Models\OrderForm;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Quotes\LockService;
use App\Services\Failures\LockAcquisitionFailure;
use App\Services\QuoteVersions\VoidService as QuoteVersionVoidService;

/**
 * Port of Rails' OrderForms::VoidService
 * (app/services/order_forms/void_service.rb) — voiding a generated form
 * cascades a void onto its quote version (reason: cascade_of_voided).
 */
class VoidService extends BaseService
{
    public function __construct(
        private readonly ?OrderForm $orderForm,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('order_form');
        $orderForm = $this->orderForm;

        if ($orderForm === null) {
            return $result->notFoundFailure('order_form');
        }

        if (! $this->orderFormsEnabled($orderForm->organization)) {
            return $result->forbiddenFailure();
        }

        try {
            DB::transaction(function () use ($orderForm, $result): void {
                LockService::call($orderForm->quoteVersion->quote, function () use ($orderForm, $result): void {
                    $orderForm->refresh();

                    if (! $orderForm->isGenerated()) {
                        $result->singleValidationFailure('not_voidable', 'status');

                        return;
                    }

                    $orderForm->status = 'voided';
                    $orderForm->voided_at = now();
                    $orderForm->void_reason = 'manual';
                    $orderForm->save();

                    // Rails: SendWebhookJob "order_form.voided" +
                    // Utils::ActivityLog — TODO(port).

                    QuoteVersionVoidService::callBang(
                        quoteVersion: $orderForm->quoteVersion,
                        reason: 'cascade_of_voided',
                    );

                    $result->order_form = $orderForm;
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
