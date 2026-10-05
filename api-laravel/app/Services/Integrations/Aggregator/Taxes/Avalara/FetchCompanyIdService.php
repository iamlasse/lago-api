<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Taxes\Avalara;

use App\Services\BaseResult;
use App\Http\Client\LagoHttpError;
use App\Services\Integrations\Aggregator\BaseService;

/**
 * Port of Rails' Integrations::Aggregator::Taxes::Avalara::FetchCompanyIdService
 * (…/aggregator/taxes/avalara/fetch_company_id_service.rb) — the Nango
 * companies lookup resolving the Avalara company behind the configured
 * company_code.
 */
class FetchCompanyIdService extends BaseService
{
    public function __construct(?\App\Models\Integration $integration)
    {
        parent::__construct($integration);
    }

    public function actionPath(): string
    {
        return "v1/{$this->provider()}/companies";
    }

    public function execute(): BaseResult
    {
        $this->result = BaseResult::of('company');

        // TODO(port): throttle!(:avalara) — the Throttling subsystem.

        try {
            $response = $this->http_client()->postWithResponse($this->payload(), $this->headers());
            $body = json_decode((string) $response->body(), true);

            $receivedCompany = $body['companies'][0] ?? null;

            if ($receivedCompany === null || $receivedCompany === []) {
                $code = 'company_not_found';
                $message = 'Company cannot be found in Avalara based on the provided code';

                $this->deliver_integration_error_webhook($code, $message);

                $this->result->serviceFailure(code: $code, message: $message);

                return $this->result;
            }

            $this->result->company = $receivedCompany;

            return $this->result;
        } catch (LagoHttpError $e) {
            $code = $this->code($e);
            $message = $this->message($e);

            $this->deliver_integration_error_webhook($code, $message);

            $this->result->serviceFailure(code: $code, message: $message);

            return $this->result;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payload(): array
    {
        return [
            [
                'company_code' => $this->integration->getFromSettings('company_code'),
            ],
        ];
    }
}
