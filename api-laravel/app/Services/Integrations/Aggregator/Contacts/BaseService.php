<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Contacts;

use App\Models\Customer;
use App\Services\Integrations\Aggregator\BaseService as AggregatorBaseService;

/**
 * Port of Rails' Integrations::Aggregator::Contacts::BaseService
 * (…/aggregator/contacts/base_service.rb) — the shared response processing
 * of the Nango contacts endpoints.
 */
abstract class BaseService extends AggregatorBaseService
{
    /** The per-service result the processing writes into (set by the subclass). */
    protected \App\Services\BaseResult $result;

    protected function result(): \App\Services\BaseResult
    {
        return $this->result;
    }

    abstract protected function customer(): ?Customer;

    protected function headers(): array
    {
        return [
            'Connection-Id' => $this->integration->getFromSecrets('connection_id'),
            'Authorization' => 'Bearer '.$this->secret_key(),
            'Provider-Config-Key' => $this->providerKey(),
        ];
    }

    protected function deliver_success_webhook(Customer $customer, string $webhookCode): void
    {
        \App\Jobs\SendWebhookJob::performLater($webhookCode, $customer);
    }

    /**
     * Rails: `process_hash_result` — the succeededContacts first row wins;
     * failures deliver the error webhook without failing the service (the
     * contact stays unresolved).
     *
     * @param  array<string, mixed>  $body
     */
    protected function process_hash_result(array $body): void
    {
        $contact = $body['succeededContacts'][0] ?? null;
        $contactId = $contact['id'] ?? null;
        $email = $contact['email'] ?? null;

        if ($contactId !== null) {
            $this->result()->contact_id = $contactId;

            if ($email !== null && $email !== '') {
                $this->result()->email = $email;
            }

            return;
        }

        $message = 'Service failure';

        if (array_key_exists('failedContacts', $body)) {
            $message = implode('. ', array_map(
                fn ($error) => (string) ($error['Message'] ?? ''),
                $body['failedContacts'][0]['validation_errors'] ?? [],
            ));
        } else {
            $message = (string) ($body['error']['payload']['message'] ?? $message);
        }

        $code = 'Validation error';

        $this->deliver_error_webhook($this->customer(), $code, $message);
    }

    /**
     * Rails: `process_string_result` — a bare string body is the contact id.
     */
    protected function process_string_result(mixed $body): void
    {
        $this->result()->contact_id = is_string($body) ? $body : (string) $body;
    }

    protected function webhook_code(): string
    {
        return match ($this->provider()) {
            'hubspot' => 'customer.crm_provider_created',
            default => 'customer.accounting_provider_created',
        };
    }
}
