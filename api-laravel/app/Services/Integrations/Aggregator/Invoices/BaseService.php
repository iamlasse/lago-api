<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Invoices;

use App\Models\Invoice;
use App\Models\Integration;
use App\Models\IntegrationCustomer;
use App\Services\Integrations\Aggregator\BaseService as AggregatorBaseService;

/**
 * Port of Rails' Integrations::Aggregator::Invoices::BaseService
 * (…/aggregator/invoices/base_service.rb) — the invoice collector resolves
 * its integration through the customer's first accounting-kind integration
 * customer.
 */
abstract class BaseService extends AggregatorBaseService
{
    public function __construct(protected readonly Invoice $invoice)
    {
        // Rails passes the resolved integration up to the aggregator base.
        parent::__construct($invoice->customer?->integrationCustomers()->accountingKind()->first()?->integration);
    }

    protected function headers(): array
    {
        return [
            'Connection-Id' => $this->integration->getFromSecrets('connection_id'),
            'Authorization' => 'Bearer '.$this->secret_key(),
            'Provider-Config-Key' => $this->providerKey(),
        ];
    }

    /** Rails: `integration` — nil unless the customer has an accounting integration customer. */
    protected function integration(): ?Integration
    {
        return $this->integration;
    }

    /** Rails: `integration_customer` — the customer's first accounting-kind row. */
    protected function integration_customer(): ?IntegrationCustomer
    {
        return $this->invoice->customer?->integrationCustomers()->accountingKind()->first();
    }

    protected function customer(): ?object
    {
        return $this->invoice->customer;
    }
}
