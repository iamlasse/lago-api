<?php

declare(strict_types=1);

namespace App\Services\OrderForms;

use App\Models\Order;
use App\Support\License;
use App\Models\OrderForm;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Support\Utils\Datetime;
use Illuminate\Support\Facades\DB;
use App\Services\Quotes\LockService;
use Illuminate\Database\QueryException;
use App\Services\QuoteVersions\DealExpiration;
use App\Services\Failures\LockAcquisitionFailure;

/**
 * Port of Rails' OrderForms::MarkAsSignedService
 * (app/services/order_forms/mark_as_signed_service.rb).
 *
 * Signing is the handoff to the Orders execution: the order form flips to
 * signed and the order row is created inside the quote aggregate lock. The
 * execution itself is dispatched later (POST /orders/:id/execute), the same
 * way Rails does it.
 *
 * TODO(port): the signed_document attachment (ActiveStorage blobs are not
 * ported); the value is accepted and validated, nothing is stored.
 */
class MarkAsSignedService extends BaseService
{
    public function __construct(
        private readonly ?OrderForm $orderForm,
        private readonly mixed $signedDocument = null,
        private readonly mixed $executionMode = null,
        private readonly mixed $executeAt = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('order_form', 'order');
        $orderForm = $this->orderForm;

        if ($orderForm === null) {
            return $result->notFoundFailure('order_form');
        }

        if (! $this->orderFormsEnabled($orderForm->organization)) {
            return $result->forbiddenFailure();
        }

        $this->validateExecutionSettings($result);

        if ($result->failure()) {
            return $result;
        }

        // Rails: signed_document_attachment — the base64 document is decoded
        // and validated before anything is written. TODO(port): the blob
        // itself (ActiveStorage).
        if (! $this->validateSignedDocument($result)) {
            return $result;
        }

        try {
            DB::transaction(function () use ($orderForm, $result): void {
                LockService::call($orderForm->quoteVersion->quote, function () use ($orderForm, $result): void {
                    $orderForm->refresh();

                    if (! $orderForm->isGenerated()) {
                        $result->singleValidationFailure('not_signable', 'status');

                        return;
                    }

                    $orderForm->status = 'signed';
                    $orderForm->signed_at = now();
                    $orderForm->save();

                    // Rails: SendWebhookJob "order_form.signed" + activity
                    // logs (signed / file_uploaded) — TODO(port).

                    $order = new Order;
                    $order->organization_id = $orderForm->organization_id;
                    $order->customer_id = $orderForm->customer_id;
                    $order->order_form_id = $orderForm->id;
                    $order->status = 'created';
                    $order->execution_mode = $this->executionMode;
                    $order->execute_at = $this->executeAt;
                    $order->save();

                    // Rails: SendWebhookJob "order.created" +
                    // Utils::ActivityLog — TODO(port).

                    $result->order = $order;
                    $result->order_form = $orderForm;
                });
            });
        } catch (LockAcquisitionFailure) {
            return $result->singleValidationFailure('concurrency_conflict', 'base');
        } catch (QueryException $e) {
            // Rails: rescue ActiveRecord::RecordNotUnique — one order per
            // order form.
            if ((int) ($e->errorInfo[0] ?? 0) === 23505 || str_contains($e->getMessage(), '23505')) {
                return $result->singleValidationFailure('value_already_exist', 'order_form_id');
            }

            throw $e;
        }

        return $result;
    }

    // -- Execution settings (OrderForms::ExecutionSettingsValidation) -------------

    protected function validateExecutionSettings(BaseResult $result): void
    {
        $this->validateExecutionMode($result);
        if ($result->failure()) {
            return;
        }

        $this->validateExecuteAt($result);
        if ($result->failure()) {
            return;
        }

        // The dates the deal is built on are only checked for futureness when
        // it is approved, and the execution flow refuses them once past, so an
        // execution scheduled after the earliest of them would fail instead of
        // billing anything.
        $quoteVersion = $this->orderForm?->quoteVersion;

        if ($quoteVersion !== null && ! DealExpiration::covers($quoteVersion, $this->executeAt)) {
            $result->singleValidationFailure('after_deal_expiration', 'execute_at');
        }
    }

    protected function validateExecutionMode(BaseResult $result): void
    {
        if (($this->executionMode === null || $this->executionMode === '')
            && ($this->executeAt === null || $this->executeAt === '')) {
            return;
        }

        if ($this->executionMode === null || $this->executionMode === '') {
            $result->singleValidationFailure('value_is_mandatory', 'execution_mode');

            return;
        }

        if (! in_array($this->executionMode, Order::EXECUTION_MODES, true)) {
            $result->singleValidationFailure('value_is_invalid', 'execution_mode');
        }
    }

    protected function validateExecuteAt(BaseResult $result): void
    {
        if ($this->executeAt === null || $this->executeAt === '') {
            return;
        }

        if (Datetime::futureDate($this->executeAt)) {
            return;
        }

        $result->singleValidationFailure('invalid_date', 'execute_at');
    }

    /**
     * Rails: signed_document_attachment — a blank document attaches nothing;
     * a payload that does not decode answers invalid_format.
     */
    protected function validateSignedDocument(BaseResult $result): bool
    {
        if ($this->signedDocument === null || $this->signedDocument === '') {
            return true;
        }

        if (! is_string($this->signedDocument)) {
            $result->singleValidationFailure('invalid_format', 'signed_document');

            return false;
        }

        $decoded = base64_decode($this->signedDocument, true);

        if ($decoded === false || $decoded === '') {
            $result->singleValidationFailure('invalid_format', 'signed_document');

            return false;
        }

        // TODO(port): the content-type / size validation of the decoded blob
        // (ActiveStorage checks SIGNED_DOCUMENT_CONTENT_TYPES / MAX_SIZE on
        // attach; the port has no blob store yet).

        return true;
    }

    /** Rails: OrderForms::Premium#order_forms_enabled?. */
    protected function orderFormsEnabled(object $organization): bool
    {
        return License::premium()
            && in_array('order_forms', (array) ($organization->feature_flags ?? []), true);
    }
}
