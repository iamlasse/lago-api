<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Companies;

use App\Models\Customer;
use App\Models\Integration;
use App\Services\BaseResult;
use App\Http\Client\LagoHttpError;
use App\Services\Integrations\Aggregator\RequestLimitError;
use App\Services\Integrations\Hubspot\Companies\DeployPropertiesService;
use App\Services\Integrations\Aggregator\Companies\Payloads\Factory as PayloadsFactory;

/**
 * Port of Rails' Integrations::Aggregator::Companies::CreateService
 * (…/aggregator/companies/create_service.rb) — the Nango company creation
 * (the HubSpot company-customer sync leg).
 *
 * TODO(port): the Throttling subsystem — Rails throttles the hubspot calls
 * through `throttle!(:hubspot)`; every throttle call site is a no-op here.
 */
class CreateService extends BaseService
{
    public function __construct(
        Integration $integration,
        public readonly ?Customer $customer,
        public readonly ?string $subsidiary_id,
    ) {
        parent::__construct($integration);
    }

    public function execute(): BaseResult
    {
        $this->result = BaseResult::of('contact_id', 'email');

        DeployPropertiesService::call(integration: $this->integration);

        try {
            $response = $this->http_client()->postWithResponse($this->params(), $this->headers());
            $body = json_decode((string) $response->body(), true);

            if (is_array($body)) {
                $this->process_hash_result($body);
            } else {
                $this->process_string_result($body);
            }

            if ($this->result()->contact_id === null) {
                return $this->result();
            }

            $this->deliver_success_webhook($this->customer(), $this->webhook_code());

            return $this->result();
        } catch (LagoHttpError $e) {
            if ($this->request_limit_error($e)) {
                throw new RequestLimitError($e->getMessage());
            }

            $code = $this->code($e);
            $message = $this->message($e);

            $this->deliver_error_webhook($this->customer(), $code, $message);

            $this->result()->serviceFailure(code: $code, message: $message);

            return $this->result();
        }
    }

    protected function customer(): ?Customer
    {
        return $this->customer;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function params(): array
    {
        return PayloadsFactory::new_instance(
            integration: $this->integration,
            integration_customer: null,
            customer: $this->customer,
            subsidiary_id: $this->subsidiary_id,
        )->create_body();
    }
}
