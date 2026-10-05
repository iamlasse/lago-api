<?php

declare(strict_types=1);

namespace App\Services\OrderForms;

use App\Support\License;
use App\Models\OrderForm;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Quotes\LockService;
use App\Services\QuoteVersions\VoidService as QuoteVersionVoidService;

/**
 * Port of Rails' OrderForms::ExpireService
 * (app/services/order_forms/expire_service.rb) — the clock-driven expiry
 * (OrderForms::ExpireJob selects with OrderForm::expirable). Expiring a
 * generated form cascades a void onto its quote version (reason:
 * cascade_of_expired).
 */
class ExpireService extends BaseService
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

        // The lock failure (Rails: FailedToAcquireLock) bubbles out of the
        // service here; the clock job is the one rescuing it (skipping the
        // form until the next run).
        DB::transaction(function () use ($orderForm, $result): void {
            LockService::call($orderForm->quoteVersion->quote, function () use ($orderForm, $result): void {
                $orderForm->refresh();

                if ($orderForm->isVoided()) {
                    $result->forbiddenFailure('order_form_is_voided');

                    return;
                }

                if ($orderForm->isSigned()) {
                    $result->forbiddenFailure('order_form_is_signed');

                    return;
                }

                if ($orderForm->isExpired()) {
                    $result->order_form = $orderForm;

                    return;
                }

                $orderForm->status = 'expired';
                $orderForm->voided_at = now();
                $orderForm->void_reason = 'expired';
                $orderForm->save();

                // Rails: SendWebhookJob "order_form.expired" +
                // Utils::ActivityLog — TODO(port).

                QuoteVersionVoidService::callBang(
                    quoteVersion: $orderForm->quoteVersion,
                    reason: 'cascade_of_expired',
                );

                $result->order_form = $orderForm;
            });
        });

        return $result;
    }

    /** Rails: OrderForms::Premium#order_forms_enabled?. */
    protected function orderFormsEnabled(object $organization): bool
    {
        return License::premium()
            && in_array('order_forms', (array) ($organization->feature_flags ?? []), true);
    }
}
