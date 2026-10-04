<?php

declare(strict_types=1);

namespace App\Services\Orders;

use Throwable;
use App\Models\Order;
use App\Models\Quote;
use App\Support\License;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Orders::UpdateService
 * (app/services/orders/update_service.rb) — execution_mode / execute_at are
 * the only editable fields, and only while the order is still created.
 *
 * Rails carries the execution-settings checks in the
 * OrderForms::ExecutionSettingsValidation concern (shared with the order
 * forms' mark-as-signed service, a later slice); they live here until that
 * slice needs them too.
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?Order $order,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('order');
        $order = $this->order;

        if ($order === null) {
            return $result->notFoundFailure('order');
        }

        if (! $this->orderFormsEnabled($order->organization)) {
            return $result->forbiddenFailure('feature_unavailable');
        }

        $this->validateExecutionSettings($result);

        if ($result->failure()) {
            return $result;
        }

        try {
            DB::transaction(function () use ($order, $result): void {
                // Rails: Quotes::LockService.call(quote: order.quote).
                // TODO(port): the advisory xact lock — row lock for now.
                $quoteId = $order->orderForm?->quoteVersion?->quote_id;

                if ($quoteId !== null) {
                    Quote::query()->whereKey($quoteId)->lockForUpdate()->first();
                }

                $order->refresh();

                if (! $order->isCreated()) {
                    $result->singleValidationFailure('not_editable', 'status')->raiseIfError();
                }

                if (array_key_exists('execution_mode', $this->params)) {
                    $order->execution_mode = $this->params['execution_mode'];
                }

                if (array_key_exists('execute_at', $this->params)) {
                    $order->execute_at = $this->params['execute_at'];
                }

                if (($errors = $order->validateAttributes()) !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $order->save();

                $result->order = $order;
            });

            return $result;
        } catch (\App\Services\Failures\FailedResult $e) {
            if ($e->result !== $result) {
                return $result->failWithError($e);
            }

            return $result;
        }
    }

    // -- Execution settings validation (OrderForms::ExecutionSettingsValidation)

    /**
     * Rails: `validate_execution_mode` — mandatory whenever any execution
     * setting is being set, and restricted to the EXECUTION_MODES values.
     */
    protected function validateExecutionMode(BaseResult $result, mixed $executionMode, mixed $executeAt): void
    {
        if (($executionMode === null || $executionMode === '') && ($executeAt === null || $executeAt === '')) {
            return;
        }

        if ($executionMode === null || $executionMode === '') {
            $result->singleValidationFailure('value_is_mandatory', 'execution_mode');

            return;
        }

        if (in_array($executionMode, Order::EXECUTION_MODES, true)) {
            return;
        }

        $result->singleValidationFailure('value_is_invalid', 'execution_mode');
    }

    /** Rails: `validate_execute_at` — a scheduled execution must be future. */
    protected function validateExecuteAt(BaseResult $result, mixed $executeAt): void
    {
        if ($executeAt === null || $executeAt === '') {
            return;
        }

        $date = $this->parseDate($executeAt);

        if ($date !== null && $date->isFuture()) {
            return;
        }

        $result->singleValidationFailure('invalid_date', 'execute_at');
    }

    /**
     * Rails: `validate_deal_expiration` — the dates the deal is built on are
     * only checked for futureness at approval, so an execution scheduled
     * past the deal's own window would fail at execution time anyway.
     * TODO(port): QuoteVersions::DealExpiration (the quotes slice owns the
     * deal dates on the quote version) — until then the check is a no-op,
     * matching a quote version that carries no expiration.
     */
    protected function validateDealExpiration(BaseResult $result, mixed $executeAt, ?object $quoteVersion): void
    {
        //
    }

    private function validateExecutionSettings(BaseResult $result): void
    {
        $effectiveExecutionMode = array_key_exists('execution_mode', $this->params)
            ? $this->params['execution_mode']
            : $this->order?->execution_mode?->value;

        $effectiveExecuteAt = array_key_exists('execute_at', $this->params)
            ? $this->params['execute_at']
            : $this->order?->execute_at?->toISOString();

        $this->validateExecutionMode($result, $effectiveExecutionMode, $effectiveExecuteAt);

        if ($result->failure()) {
            return;
        }

        $this->validateExecuteAt($result, $this->params['execute_at'] ?? null);

        if ($result->failure()) {
            return;
        }

        $this->validateDealExpiration($result, $effectiveExecuteAt, $this->order?->quoteVersion());
    }

    /**
     * Rails: Utils::Datetime.future_date? — parses an ISO8601 value and
     * compares to now; an unparseable value reaches the model validation
     * instead (Rails' datetime cast raises there).
     */
    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Rails: OrderForms::Premium#order_forms_enabled?.
     */
    private function orderFormsEnabled(object $organization): bool
    {
        return License::premium()
            && in_array('order_forms', (array) ($organization->feature_flags ?? []), true);
    }
}
