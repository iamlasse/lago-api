<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Payments;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\BaseResult;
use App\Http\Client\LagoHttpError;
use App\Models\IntegrationResource;
use App\Services\Integrations\Aggregator\BaseService;
use App\Jobs\Integrations\Aggregator\Payments\CreateJob;
use App\Services\Integrations\Aggregator\RequestLimitError;
use App\Services\Integrations\Aggregator\BasePayload\Failure as PayloadFailure;
use App\Services\Integrations\Aggregator\Payments\Payloads\Factory as PayloadsFactory;

/**
 * Port of Rails' Integrations::Aggregator::Payments::CreateService
 * (app/services/integrations/aggregator/payments/create_service.rb) — the
 * Nango payment push for the accounting providers, recording the external id
 * in IntegrationResource.
 *
 * The collector resolves its integration through the payable's customer's
 * first accounting-kind integration customer (Rails: Invoices::BaseService
 * with payment.payable).
 *
 * TODO(port): the Throttling subsystem (the throttle! call is a no-op, see
 * the aggregator BaseService).
 */
class CreateService extends BaseService
{
    public function __construct(
        public readonly ?Payment $payment,
    ) {
        // Rails: super(invoice: payment.payable) — the invoice collector base
        // resolves the integration off the payable's customer; a non-invoice
        // payable (payment request) has no accounting integration here.
        parent::__construct(
            $this->payment?->payable instanceof Invoice
                ? $this->payment->payable->customer?->integrationCustomers()->accountingKind()->first()?->integration
                : null,
        );
    }

    public function actionPath(): string
    {
        return 'v1/'.$this->provider().'/payments';
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('payment_id', 'external_id');

        try {
            // Rails: call guards — no integration, sync disabled, non-finalized
            // payable or already-synced payments answer without a payload push.
            if ($this->integration === null) {
                return $result;
            }

            if (! $this->integration->getFromSettings('sync_payments')) {
                return $result;
            }

            $invoice = $this->invoice();

            if (! $invoice instanceof Invoice || ! $invoice->isFinalized()) {
                return $result;
            }

            if ($this->payload()->integration_payment() !== null) {
                return $result;
            }

            $response = $this->http_client()->postWithResponse($this->payload()->body(), $this->headers());
            $body = json_decode((string) $response->body(), true);

            if (is_array($body)) {
                $this->process_hash_result($body, $result);
            } else {
                $this->process_string_result($body, $result);
            }

            if ($result->external_id === null) {
                return $result;
            }

            IntegrationResource::query()->create([
                'organization_id' => $this->integration->organization_id,
                'integration_id' => $this->integration->id,
                'external_id' => $result->external_id,
                'syncable_id' => $this->payment->id,
                'syncable_type' => 'Payment',
                'resource_type' => IntegrationResource::RESOURCE_TYPE_PAYMENT,
            ]);

            return $result;
        } catch (LagoHttpError $e) {
            if ($this->request_limit_error($e)) {
                throw new RequestLimitError($e->getMessage());
            }

            $code = $this->code($e);
            $message = $this->message($e);

            $this->deliver_error_webhook($this->customer(), $code, $message);

            $errorCode = (int) ($e->errorCode ?? 0);

            if ($errorCode === 500 || $errorCode === 424) {
                throw $e;
            }

            return $result;
        } catch (PayloadFailure $e) {
            $this->deliver_error_webhook(
                $this->customer(),
                $e->code(),
                ucwords(str_replace('_', ' ', $e->code())),
            );

            return $result;
        }
    }

    /** Rails: `call_async` — the CreateJob enqueue with the payment id. */
    public function call_async(): BaseResult
    {
        $result = BaseResult::of('payment_id', 'external_id');

        if ($this->payment === null) {
            return $result->notFoundFailure('payment');
        }

        dispatch(new \App\Jobs\Integrations\Aggregator\Payments\CreateJob($this->payment));

        $result->payment_id = $this->payment->id;

        return $result;
    }

    /** Rails: `customer` — delegated to the payment. */
    protected function customer(): ?object
    {
        return $this->payment?->customer;
    }

    /** Rails: `invoice` — the payment's payable. */
    protected function invoice(): ?object
    {
        return $this->payment?->payable;
    }

    protected function headers(): array
    {
        return [
            'Connection-Id' => $this->integration->getFromSecrets('connection_id'),
            'Authorization' => 'Bearer '.$this->secret_key(),
            'Provider-Config-Key' => $this->providerKey(),
        ];
    }

    private function payload(): Payloads\BasePayload
    {
        return PayloadsFactory::new_instance(
            integration: $this->integration,
            payment: $this->payment,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function process_hash_result(array $body, BaseResult $result): void
    {
        $externalId = $body['succeededPayment'][0]['id'] ?? null;

        if (is_string($externalId) && $externalId !== '') {
            $result->external_id = $externalId;

            return;
        }

        $message = implode('. ', array_map(
            fn ($error) => (string) ($error['Message'] ?? ''),
            $body['failedPayments'][0]['validation_errors'] ?? [],
        ));
        $code = 'Validation error';

        $this->deliver_error_webhook($this->customer(), $code, $message);
    }

    private function process_string_result(mixed $body, BaseResult $result): void
    {
        if (is_string($body)) {
            $result->external_id = $body;
        }
    }
}
