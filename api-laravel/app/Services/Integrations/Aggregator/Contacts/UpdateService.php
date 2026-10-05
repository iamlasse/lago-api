<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Contacts;

use App\Services\BaseResult;
use App\Http\Client\LagoHttpError;
use App\Models\IntegrationCustomer;
use App\Services\Integrations\Aggregator\RequestLimitError;
use App\Services\Integrations\Aggregator\Contacts\Payloads\Factory as PayloadsFactory;

/**
 * Port of Rails' Integrations::Aggregator::Contacts::UpdateService
 * (…/aggregator/contacts/update_service.rb) — the Nango contact update
 * (PUT on the external contact id).
 */
class UpdateService extends BaseService
{
    public function __construct(
        \App\Models\Integration $integration,
        public readonly ?IntegrationCustomer $integration_customer,
    ) {
        parent::__construct($integration);
    }

    public function actionPath(): string
    {
        return "v1/{$this->provider()}/contacts";
    }

    public function execute(): BaseResult
    {
        $this->result = BaseResult::of('contact_id', 'email');

        try {
            $response = $this->http_client()->putWithResponse($this->params(), $this->headers());
            $body = json_decode((string) $response->body(), true);

            if (is_array($body)) {
                $this->process_hash_result($body);
            } else {
                $this->process_string_result($body);
            }

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

    protected function customer(): ?\App\Models\Customer
    {
        return $this->integration_customer?->customer;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function params(): array
    {
        return PayloadsFactory::new_instance(
            integration: $this->integration,
            integration_customer: $this->integration_customer,
            customer: $this->customer(),
            subsidiary_id: null,
        )->update_body();
    }
}
