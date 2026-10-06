<?php

declare(strict_types=1);

namespace App\Services\PaymentRequests;

use App\Models\Invoice;
use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentRequest;
use Illuminate\Support\Facades\DB;
use App\Models\PaymentRequestAppliedInvoice;

/**
 * Port of Rails' PaymentRequests::CreateService — POST /api/v1/payment_requests
 * (premium dunning payment link over overdue invoices).
 *
 * Preconditions (Rails order): premium license, customer exists, invoices
 * exist, all overdue, single currency, single billing entity, all ready
 * for payment processing, payment_method override valid. Then the payment
 * request + applied invoice rows in one transaction; "payment_request.created"
 * webhook fires after commit.
 *
 * TODO(port): PaymentRequests::Payments::CreateService.call_async (the
 * automatic charge attempt on the request) and PaymentRequestMailer
 * (email slice).
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly object $organization,
        private readonly array $params,
        private readonly ?object $dunningCampaign = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment_request', 'payment_method');

        $customer = $this->organization->customers()
            ->where('external_id', $this->params['external_customer_id'] ?? null)
            ->first();

        $paymentMethodParams = $this->params['payment_method'] ?? [];

        // Rails: valid_payment_method? — only payment_method_id supported;
        // unknown id fails with payment_method not found.
        $paymentMethod = null;

        if (($paymentMethodParams['payment_method_id'] ?? null) !== null) {
            $paymentMethod = $customer?->paymentMethods()
                ->where('id', $paymentMethodParams['payment_method_id'])
                ->first();

            if ($paymentMethod === null) {
                return $result->notFoundFailure('payment_method');
            }
        }

        $invoices = $customer !== null
            ? $customer->invoices()
                ->whereNot('status', 8) // Invoice::STATUS deleted
                ->whereIn('id', $this->params['lago_invoice_ids'] ?? [])
                ->get()
            : collect();

        $preconditionFailure = $this->checkPreconditions($result, $customer, $invoices);

        if ($preconditionFailure !== null) {
            return $preconditionFailure;
        }

        $totalAmountCents = (int) $invoices->sum(fn (Invoice $invoice): int => (int) $invoice->total_amount_cents - (int) $invoice->total_paid_amount_cents);
        $currency = $invoices->first()->currency;
        $email = $this->params['email'] ?? $customer->email;

        $paymentRequest = DB::transaction(function () use ($customer, $invoices, $totalAmountCents, $currency, $email): PaymentRequest {
            $request = new PaymentRequest([
                'organization_id' => $this->organization->id,
                'customer_id' => $customer->id,
                'amount_cents' => $totalAmountCents,
                'amount_currency' => $currency,
                'email' => $email,
                // Rails: the dunning attempt that created the request
                // (DunningCampaigns::ProcessAttemptService slice).
                'dunning_campaign_id' => $this->dunningCampaign?->id,
            ]);
            $request->save();

            foreach ($invoices as $invoice) {
                PaymentRequestAppliedInvoice::create([
                    'payment_request_id' => $request->id,
                    'invoice_id' => $invoice->id,
                    'organization_id' => $invoice->organization_id,
                ]);
            }

            return $request;
        });

        SendWebhookJob::performLater('payment_request.created', $paymentRequest);

        // Rails: PaymentRequests::Payments::CreateService.call_async — the
        // automatic charge attempt on the freshly created request.
        (new Payments\CreateService(
            payable: $paymentRequest,
            paymentMethodParams: $paymentMethodParams,
        ))->callAsync();

        // TODO(port): PaymentRequestMailer.requested when the auto-charge
        // fails (mailer slice).

        $result->payment_request = $paymentRequest;
        $result->payment_method = $paymentMethod;

        return $result;
    }

    /** Returns a failure result, or null when all preconditions pass. */
    private function checkPreconditions(BaseResult $result, $customer, $invoices): ?BaseResult
    {
        if (! $this->premium()) {
            return $result->forbiddenFailure();
        }

        if ($customer === null) {
            return $result->notFoundFailure('customer');
        }

        if ($invoices->isEmpty()) {
            return $result->notFoundFailure('invoice');
        }

        if ($invoices->contains(fn (Invoice $invoice): bool => ! $invoice->payment_overdue)) {
            return $result->notAllowedFailure('invoices_not_overdue');
        }

        if ($invoices->pluck('currency')->unique()->count() > 1) {
            return $result->notAllowedFailure('invoices_have_different_currencies');
        }

        if ($invoices->pluck('billing_entity_id')->unique()->count() > 1) {
            return $result->notAllowedFailure('invoices_have_different_billing_entities');
        }

        if ($invoices->contains(fn (Invoice $invoice): bool => ! $invoice->ready_for_payment_processing)) {
            return $result->notAllowedFailure('invoices_not_ready_for_payment_processing');
        }

        return null;
    }
}
