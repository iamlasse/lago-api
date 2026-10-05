<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Taxes\Invoices;

use App\Models\Integration;
use App\Services\BaseResult;
use App\Http\Client\LagoHttpError;
use App\Services\Integrations\Aggregator\TimeoutError;
use App\Services\Integrations\Aggregator\BadGatewayError;
use App\Services\Integrations\Aggregator\TaskExpiredError;
use App\Services\Integrations\Aggregator\RequestLimitError;
use App\Services\Integrations\Aggregator\TaskInProgressError;
use App\Services\Integrations\Aggregator\OrchestratorFailureError;
use App\Services\Integrations\Aggregator\Taxes\Invoices\Payloads\Factory as PayloadsFactory;

/**
 * Port of Rails' Integrations::Aggregator::Taxes::Invoices::CreateDraftService
 * (…/taxes/invoices/create_draft_service.rb) — the Nango "draft_invoices"
 * tax request (draft invoices and advance charges).
 */
class CreateDraftService extends BaseService
{
    public function actionPath(): string
    {
        return "v1/{$this->provider()}/draft_invoices";
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
     * @return list<array<string, mixed>>
     */
    private function payload(): array
    {
        return PayloadsFactory::new_instance(
            integration: $this->integration(),
            invoice: $this->invoice,
            customer: $this->customer(),
            integration_customer: $this->integration_customer(),
            fees: $this->payload_fees(),
        )->body();
    }

    private function parse_response(mixed $body): array
    {
        if (is_array($body)) {
            return $body;
        }

        $decoded = json_decode((string) $body, true);

        return is_array($decoded) ? $decoded : [(string) $body];
    }
}
