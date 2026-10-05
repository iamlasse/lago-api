<?php

declare(strict_types=1);

namespace App\Services\PaymentIntents;

use Throwable;
use App\Models\Invoice;
use App\Services\BaseResult;
use App\Models\PaymentIntent;
use App\Services\BaseService;
use App\Services\Failures\FailedResult;
use App\Services\Invoices\Payments\PaymentProviders\Factory;

/**
 * Port of Rails' PaymentIntents::FetchService — returns the invoice's
 * hosted-checkout payment intent, expiring the stale one first and
 * generating (then storing) the payment URL through the customer's payment
 * provider when the intent does not carry one yet. An empty URL from the
 * provider fails with "payment_provider_error".
 */
class FetchService extends BaseService
{
    public function __construct(
        private readonly Invoice $invoice,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment_intent');

        try {
            if ($this->invoice->id === null || ! $this->invoice->exists) {
                return $result->notFoundFailure('invoice');
            }

            PaymentIntent::query()
                ->where('invoice_id', $this->invoice->id)
                ->awaitingExpiration()
                ->each(function (PaymentIntent $paymentIntent): void {
                    $paymentIntent->setExpired();
                    $paymentIntent->save();
                });

            $paymentIntent = PaymentIntent::query()
                ->where('invoice_id', $this->invoice->id)
                ->where('organization_id', $this->invoice->organization_id)
                ->nonExpired()
                ->first();

            if ($paymentIntent === null) {
                $paymentIntent = new PaymentIntent([
                    'invoice_id' => $this->invoice->id,
                    'organization_id' => $this->invoice->organization_id,
                    'expires_at' => now()->addDay(),
                ]);
                $paymentIntent->setActive();
                $paymentIntent->save();
            }

            if (($paymentIntent->payment_url ?? null) === null || $paymentIntent->payment_url === '') {
                $paymentUrlResult = Factory::for($this->invoice)::generatePaymentUrl($this->invoice, $paymentIntent);

                $paymentUrlResult->raiseIfError();

                if (($paymentUrlResult->payment_url ?? null) === null || $paymentUrlResult->payment_url === '') {
                    return $result->singleValidationFailure('payment_provider_error');
                }

                $paymentIntent->payment_url = $paymentUrlResult->payment_url;
                $paymentIntent->provider_session_id = $paymentUrlResult->provider_session_id ?? null;
                $paymentIntent->save();
            }

            $result->payment_intent = $paymentIntent;

            return $result;
        } catch (FailedResult $e) {
            return $e->result;
        } catch (Throwable $e) {
            throw $e;
        }
    }
}
