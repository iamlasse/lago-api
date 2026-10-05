<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Taxes\Invoices;

use App\Models\Integration;
use App\Services\BaseResult;
use App\Http\Client\LagoHttpError;
use App\Models\IntegrationResource;
use App\Services\Integrations\Aggregator\TimeoutError;
use App\Services\Integrations\Aggregator\BadGatewayError;
use App\Services\Integrations\Aggregator\TaskExpiredError;
use App\Services\Integrations\Aggregator\RequestLimitError;
use App\Services\Integrations\Aggregator\TaskInProgressError;
use App\Services\Integrations\Aggregator\OrchestratorFailureError;
use App\Services\Integrations\Aggregator\Taxes\Invoices\Payloads\Factory as PayloadsFactory;

/**
 * Port of Rails' Integrations::Aggregator::Taxes::Invoices::CreateService
 * (…/taxes/invoices/create_service.rb) — the Nango "finalized_invoices"
 * tax request.
 */
class CreateService extends BaseService
{
    public function actionPath(): string
    {
        return "v1/{$this->provider()}/finalized_invoices";
    }

    public function execute(): BaseResult
    {
        $this->result = BaseResult::of('fees', 'succeeded_id', 'invoice_id');

        $integration = $this->integration();

        if ($integration === null) {
            return $this->result;
        }

        if (! in_array($integration->type, Integration::INTEGRATION_TAX_TYPES, true)) {
            return $this->result;
        }

        if ($this->taxable_fees() === []) {
            return $this->no_taxable_fees_result();
        }

        try {
            $response = $this->http_client()->postWithResponse($this->payload(), $this->headers());

            $body = $this->parse_response($response->body());

            $this->process_response($body);
            $this->assign_external_customer_id();

            if ($integration->type === Integration::AVALARA_TYPE && $this->result->succeeded_id !== null) {
                $this->create_integration_resource();
            }

            return $this->result;
        } catch (LagoHttpError $e) {
            if ($this->request_limit_error($e)) {
                throw new RequestLimitError($e->getMessage());
            }

            if ($this->bad_gateway_error($e)) {
                throw new BadGatewayError($e->errorBody ?? $e->getMessage());
            }

            if ($this->task_in_progress_error($e)) {
                throw new TaskInProgressError($this->message($e));
            }

            if ($this->task_expired_error($e)) {
                throw new TaskExpiredError($this->message($e));
            }

            if ($this->orchestrator_failure_error($e)) {
                throw new OrchestratorFailureError($this->message($e));
            }

            $this->result->serviceFailure(code: $this->code($e), message: $this->message($e));

            return $this->result;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new TimeoutError($e->getMessage());
        }
    }

    /**
     * Rails: `payload` — the provider payload with the invoice id (and the
     * Avalara document type) stamped on the single invoice entry.
     *
     * @return list<array<string, mixed>>
     */
    private function payload(): array
    {
        $integration = $this->integration();

        $payloadBody = PayloadsFactory::new_instance(
            integration: $integration,
            invoice: $this->invoice,
            customer: $this->customer(),
            integration_customer: $this->integration_customer(),
            fees: $this->payload_fees(),
        )->body();

        $invoiceData = $payloadBody[0];
        $invoiceData['id'] = $this->invoice->id;

        if ($integration->type === Integration::AVALARA_TYPE) {
            $invoiceData['type'] = $this->invoice->isVoided() ? 'returnInvoice' : 'salesInvoice';
        }

        return [$invoiceData];
    }

    /** Rails: `create_integration_resource` — the succeeded provider invoice id. */
    private function create_integration_resource(): void
    {
        IntegrationResource::create([
            'organization_id' => $this->integration()->organization_id,
            'syncable_id' => $this->invoice->id,
            'syncable_type' => 'Invoice',
            'external_id' => $this->result->succeeded_id,
            'integration_id' => $this->integration()->id,
            'resource_type' => IntegrationResource::RESOURCE_TYPE_INVOICE,
        ]);
    }

    /**
     * Rails: `parse_response(response)` — JSON.parse(response.body); blank
     * bodies are kept verbatim so the failure paths see them (an empty body
     * from a 500 is a BadGateway).
     */
    private function parse_response(mixed $body): array
    {
        if (is_array($body)) {
            return $body;
        }

        $decoded = json_decode((string) $body, true);

        return is_array($decoded) ? $decoded : [(string) $body];
    }
}
