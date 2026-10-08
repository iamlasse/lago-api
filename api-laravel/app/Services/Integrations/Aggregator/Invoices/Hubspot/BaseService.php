<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Invoices\Hubspot;

use App\Models\Invoice;
use App\Models\IntegrationCustomer;
use App\Services\Integrations\Aggregator\BaseService as AggregatorBaseService;
use App\Services\Integrations\Aggregator\Invoices\Payloads\Factory as PayloadsFactory;
use App\Services\Integrations\Aggregator\Invoices\Payloads\Hubspot as HubspotPayload;

/**
 * Port of Rails' Integrations::Aggregator::Invoices::Hubspot::BaseService
 * (…/aggregator/invoices/hubspot/base_service.rb) — the HubSpot custom
 * object collector resolves its integration through the customer's first
 * hubspot-kind integration customer and talks to the Nango records
 * endpoint.
 *
 * NOTE: the port re-derives the integration here instead of extending the
 * accounting Invoices\BaseService, whose constructor hard-wires the
 * accounting-kind resolution.
 */
abstract class BaseService extends AggregatorBaseService
{
    public function __construct(protected readonly Invoice $invoice)
    {
        parent::__construct($invoice->customer?->integrationCustomers()->hubspotKind()->first()?->integration);
    }

    public function actionPath(): string
    {
        return 'v1/'.$this->provider().'/records';
    }

    /** Rails: `integration_customer` — the customer's first hubspot-kind row. */
    protected function integration_customer(): ?IntegrationCustomer
    {
        return $this->invoice->customer?->integrationCustomers()->hubspotKind()->first();
    }

    protected function payload(): HubspotPayload
    {
        return PayloadsFactory::new_instance(
            integration_customer: $this->integration_customer(),
            invoice: $this->invoice,
        );
    }

    protected function customer(): ?object
    {
        return $this->invoice->customer;
    }
}
