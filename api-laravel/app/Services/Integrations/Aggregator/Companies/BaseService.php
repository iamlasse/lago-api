<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Companies;

use App\Services\Integrations\Aggregator\Contacts\BaseService as ContactsBaseService;

/**
 * Port of Rails' Integrations::Aggregator::Companies::BaseService
 * (…/aggregator/companies/base_service.rb) — the shared response processing
 * of the Nango companies endpoint (the succeededCompanies shape).
 */
abstract class BaseService extends ContactsBaseService
{
    public function actionPath(): string
    {
        return 'v1/'.$this->provider().'/companies';
    }

    /**
     * Rails: `process_hash_result` — the succeededCompanies first row wins;
     * failures deliver the error webhook without failing the service (the
     * company stays unresolved).
     *
     * @param  array<string, mixed>  $body
     */
    protected function process_hash_result(array $body): void
    {
        $company = $body['succeededCompanies'][0] ?? null;
        $companyId = $company['id'] ?? null;
        $email = $company['email'] ?? null;

        if ($companyId !== null) {
            $this->result()->contact_id = $companyId;

            if ($email !== null && $email !== '') {
                $this->result()->email = $email;
            }

            return;
        }

        $message = 'Service failure';

        if (array_key_exists('failedCompanies', $body)) {
            $message = implode('. ', array_map(
                fn ($error) => (string) ($error['Message'] ?? ''),
                $body['failedCompanies'][0]['validation_errors'] ?? [],
            ));
        } else {
            $message = (string) ($body['error']['payload']['message'] ?? $message);
        }

        $code = 'Validation error';

        $this->deliver_error_webhook($this->customer(), $code, $message);
    }
}
