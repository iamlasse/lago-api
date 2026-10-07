<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Invoices;

use App\Models\Invoice;
use App\Services\BaseResult;
use App\Http\Client\LagoHttpError;
use App\Models\IntegrationResource;
use App\Services\Integrations\Aggregator\RequestLimitError;
use App\Services\Integrations\Aggregator\BasePayload\Failure as PayloadFailure;
use App\Services\Integrations\Aggregator\Invoices\Payloads\Factory as PayloadsFactory;

/**
 * Port of Rails' Integrations::Aggregator::Invoices::CreateService
 * (…/aggregator/invoices/create_service.rb) — the Nango invoice push for
 * the accounting providers, recording the external id in
 * IntegrationResource.
 *
 * TODO(port): the Throttling subsystem (the throttle! call is a no-op, see
 * the aggregator BaseService) and ReconcileService (the CreateJob's
 * look-upstream leg).
 */
class CreateService extends BaseService
{
    public const string INVALID_LOGIN_ATTEMPT = 'INVALID_LOGIN_ATTEMPT';

    /** The per-service result the processing writes into. */
    protected BaseResult $result;

    public function __construct(Invoice $invoice)
    {
        parent::__construct($invoice);
    }

    public function actionPath(): string
    {
        return "v1/{$this->provider()}/invoices";
    }

    public function execute(): BaseResult
    {
        $this->result = BaseResult::of('invoice_id', 'external_id');

        try {
            if ($this->integration === null) {
                return $this->result();
            }

            if (! $this->integration->getFromSettings('sync_invoices')) {
                return $this->result();
            }

            if (! $this->invoice->isFinalized()) {
                return $this->result();
            }

            if ($this->payload()->integration_invoice() !== null) {
                return $this->result();
            }

            $response = $this->http_client()->postWithResponse($this->payload()->body(), $this->headers());
            $body = json_decode((string) $response->body(), true);

            if (is_array($body)) {
                $this->process_hash_result($body);
            } else {
                $this->process_string_result($body);
            }

            if ($this->result()->external_id === null) {
                return $this->result();
            }

            IntegrationResource::query()->create([
                'organization_id' => $this->integration->organization_id,
                'integration_id' => $this->integration->id,
                'external_id' => $this->result()->external_id,
                'syncable_id' => $this->invoice->id,
                'syncable_type' => 'Invoice',
                'resource_type' => IntegrationResource::RESOURCE_TYPE_INVOICE,
            ]);

            return $this->result();
        } catch (LagoHttpError $e) {
            if ($this->request_limit_error($e)) {
                throw new RequestLimitError($e->getMessage());
            }

            $code = $this->code($e);
            $message = $this->message($e);

            $this->deliver_error_webhook($this->customer(), $code, $message);

            if ($this->retryable_error($e)) {
                throw $e;
            }

            return $this->result()->nonRetryableFailure(code: $code, message: $message);
        } catch (PayloadFailure $e) {
            $this->deliver_error_webhook($this->customer(), $e->code(), ucwords(str_replace('_', ' ', $e->code())));

            return $this->result()->nonRetryableFailure(
                code: $e->code(),
                message: ucwords(str_replace('_', ' ', $e->code())),
            );
        }
    }

    /** Rails: `call_async` — the CreateJob enqueue. */
    public function call_async(): BaseResult
    {
        $this->result = BaseResult::of('invoice_id', 'external_id');

        if ($this->invoice->customer === null && $this->invoice->id === null) {
            return $this->result->notFoundFailure('invoice');
        }

        dispatch(new \App\Jobs\Integrations\Aggregator\Invoices\CreateJob($this->invoice));

        $this->result()->invoice_id = $this->invoice->id;

        return $this->result();
    }

    protected function result(): BaseResult
    {
        return $this->result;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function process_hash_result(array $body): void
    {
        $externalId = $body['succeededInvoices'][0]['id'] ?? null;

        if (is_string($externalId) && $externalId !== '') {
            $this->result()->external_id = $externalId;

            return;
        }

        $message = implode('. ', array_map(
            fn ($error) => (string) ($error['Message'] ?? ''),
            $body['failedInvoices'][0]['validation_errors'] ?? [],
        ));
        $code = 'Validation error';

        $this->deliver_error_webhook($this->customer(), $code, $message);
    }

    private function process_string_result(mixed $body): void
    {
        $this->result()->external_id = is_string($body) ? $body : (string) $body;
    }

    private function retryable_error(LagoHttpError $error): bool
    {
        $errorCode = (int) ($error->errorCode ?? 0);

        $serverError = $errorCode >= 500 || $errorCode === 424;

        return $serverError && ! $this->invalid_login_attempt_error($error);
    }

    private function invalid_login_attempt_error(LagoHttpError $error): bool
    {
        return is_string($error->errorBody) && str_contains($error->errorBody, self::INVALID_LOGIN_ATTEMPT);
    }

    private function payload(): Payloads\BasePayload
    {
        return PayloadsFactory::new_instance(
            integration_customer: $this->integration_customer(),
            invoice: $this->invoice,
        );
    }
}
