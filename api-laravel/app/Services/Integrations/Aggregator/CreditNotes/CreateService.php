<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\CreditNotes;

use App\Models\CreditNote;
use App\Services\BaseResult;
use App\Http\Client\LagoHttpError;
use App\Models\IntegrationResource;
use App\Services\Integrations\Aggregator\BaseService;
use App\Jobs\Integrations\Aggregator\CreditNotes\CreateJob;
use App\Services\Integrations\Aggregator\RequestLimitError;
use App\Services\Integrations\Aggregator\BasePayload\Failure as PayloadFailure;
use App\Services\Integrations\Aggregator\CreditNotes\Payloads\Factory as PayloadsFactory;

/**
 * Port of Rails' Integrations::Aggregator::CreditNotes::CreateService
 * (app/services/integrations/aggregator/credit_notes/create_service.rb) —
 * the Nango credit note push for the accounting providers, recording the
 * external id in IntegrationResource.
 *
 * The collector resolves its integration through the credit note customer's
 * first accounting-kind integration customer (Rails: Invoices::BaseService
 * with the credit note's invoice).
 *
 * TODO(port): the Throttling subsystem (the throttle! call is a no-op, see
 * the aggregator BaseService).
 */
class CreateService extends BaseService
{
    public function __construct(
        public readonly ?CreditNote $credit_note,
    ) {
        // Rails: super(invoice: credit_note.invoice) — the invoice collector
        // base resolves the integration off the customer; the port binds the
        // credit note's customer accounting integration directly.
        parent::__construct(
            $this->credit_note?->customer?->integrationCustomers()->accountingKind()->first()?->integration,
        );
    }

    public function actionPath(): string
    {
        return 'v1/'.$this->provider().'/creditnotes';
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('credit_note_id', 'external_id');

        try {
            // Rails: call guards — no integration, sync disabled, non-finalized
            // or already-synced credit notes answer without a payload push.
            if ($this->integration === null) {
                return $result;
            }

            if (! $this->integration->getFromSettings('sync_credit_notes')) {
                return $result;
            }

            if ($this->credit_note === null || ! $this->credit_note->isFinalized()) {
                return $result;
            }

            if ($this->payload()->integration_credit_note() !== null) {
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
                'syncable_id' => $this->credit_note->id,
                'syncable_type' => 'CreditNote',
                'resource_type' => IntegrationResource::RESOURCE_TYPE_CREDIT_NOTE,
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

    /** Rails: `call_async` — the CreateJob enqueue with the credit note id. */
    public function call_async(): BaseResult
    {
        $result = BaseResult::of('credit_note_id', 'external_id');

        if ($this->credit_note === null) {
            return $result->notFoundFailure('credit_note');
        }

        CreateJob::dispatch($this->credit_note);

        $result->credit_note_id = $this->credit_note->id;

        return $result;
    }

    /** Rails: `customer` — delegated to the credit note. */
    protected function customer(): ?object
    {
        return $this->credit_note?->customer;
    }

    /** Rails: `integration_customer` — the customer's first accounting-kind row. */
    protected function integration_customer(): ?object
    {
        return $this->credit_note?->customer?->integrationCustomers()->accountingKind()->first();
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
            integration_customer: $this->integration_customer(),
            credit_note: $this->credit_note,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function process_hash_result(array $body, BaseResult $result): void
    {
        $externalId = $body['succeededCreditNotes'][0]['id'] ?? null;

        if (is_string($externalId) && $externalId !== '') {
            $result->external_id = $externalId;

            return;
        }

        $message = implode('. ', array_map(
            fn ($error) => (string) ($error['Message'] ?? ''),
            $body['failedCreditNotes'][0]['validation_errors'] ?? [],
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
