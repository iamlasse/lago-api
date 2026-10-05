<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Contacts;

use App\Models\Customer;
use App\Models\Integration;
use App\Services\BaseResult;
use App\Http\Client\LagoHttpError;
use App\Services\Integrations\Aggregator\RequestLimitError;
use App\Services\Integrations\Aggregator\Contacts\Payloads\Factory as PayloadsFactory;

/**
 * Port of Rails' Integrations::Aggregator::Contacts::CreateService
 * (…/aggregator/contacts/create_service.rb) — the Nango contact creation
 * (the Avalara integration-customer sync legs).
 *
 * TODO(port): the hubspot contacts deploy-properties leg (a no-op for the
 * tax providers) and the Throttling subsystem.
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
    public function actionPath(): string
    {
        return "v1/{$this->provider()}/contacts";
    }

    protected function customer(): ?Customer
    {
        return $this->customer;
    }

    public function execute(): BaseResult
    {
        $this->result = BaseResult::of('contact_id', 'email');

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
