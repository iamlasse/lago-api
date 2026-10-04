<?php

declare(strict_types=1);

namespace App\Services\PaymentReceipts;

use Throwable;
use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentReceipt;
use App\Jobs\PaymentReceipts\GenerateDocumentsJob;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Port of Rails' PaymentReceipts::CreateService
 * (app/services/payment_receipts/create_service.rb) — one receipt per
 * successful payment of a receipt-issuing organization.
 *
 * Rails order: payment present, organization.issue_receipts_enabled?,
 * partner accounts are skipped (nil result), payable_payment_status must be
 * "succeeded", idempotent re-entry (existing receipt returned), then the
 * INSERT — whose number is assigned by the frozen schema's
 * set_payment_receipt_number() trigger (see App\Models\PaymentReceipt) —
 * "payment_receipt.created" webhook, documents generation (notify only when
 * premium + billing_entity.email_settings has "payment_receipt.created").
 *
 * Rails' RecordNotUnique rescue (the payment_id UNIQUE index raced) reloads
 * the winner and returns it; RecordInvalid maps to the record_validation
 * failure.
 *
 * TODO(port): Utils::ActivityLog.produce(receipt, "payment_receipt.created").
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly ?object $payment,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment_receipt');

        /** @var \App\Models\Payment|null $payment */
        $payment = $this->payment;

        if ($payment === null) {
            return $result->notFoundFailure('payment');
        }

        $payable = $payment->payable;
        $organization = $payable?->organization ?? null;
        $billingEntity = $payable?->billingEntity ?? null;

        if ($organization === null || ! $organization->issueReceiptsEnabled()) {
            return $result->forbiddenFailure();
        }

        $customer = $payable->customer ?? null;

        if ($customer !== null && $customer->partnerAccount()) {
            return $result;
        }

        if ((string) $payment->payable_payment_status !== 'succeeded') {
            return $result;
        }

        // Rails: payment.payment_receipt — re-read per call (a cached null
        // relation would break the idempotent re-entry).
        $existing = $payment->paymentReceipt()->first();

        if ($existing !== null) {
            $result->payment_receipt = $existing;

            return $result;
        }

        // The INSERT runs in a savepoint so the RecordNotUnique rescue
        // (payment_id UNIQUE race) can continue in the caller's transaction.
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use (&$receipt, $payment, $organization, $billingEntity): void {
                $receipt = new PaymentReceipt([
                    'payment_id' => $payment->id,
                    'organization_id' => $organization->id,
                    'billing_entity_id' => $billingEntity?->id,
                ]);
                $receipt->save();
            });
        } catch (UniqueConstraintViolationException) {
            // Rails: rescue ActiveRecord::RecordNotUnique — the concurrent
            // creator won the payment_id UNIQUE index; return its receipt.
            $result->payment_receipt = $payment->refresh()->paymentReceipt()->first();

            return $result;
        } catch (Throwable $e) {
            if ($e instanceof \App\Services\Failures\FailedResult) {
                return $this->embedFailure($result, $e);
            }

            throw $e;
        }

        $result->payment_receipt = $receipt;

        SendWebhookJob::performLater('payment_receipt.created', $receipt);

        // TODO(port): Utils::ActivityLog.produce(receipt, "payment_receipt.created").

        GenerateDocumentsJob::dispatch(
            paymentReceipt: $receipt,
            notify: $this->shouldDeliverEmail($billingEntity),
        );

        return $result;
    }

    /** Rails: License.premium? && billing_entity.email_settings.include?("payment_receipt.created"). */
    private function shouldDeliverEmail(?\App\Models\BillingEntity $billingEntity): bool
    {
        return $this->premium()
            && $billingEntity !== null
            && $billingEntity->emailSettingsInclude('payment_receipt.created');
    }
}
