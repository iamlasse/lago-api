<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Invoice;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\Integrations\Aggregator\Invoices\CreateService;

/**
 * Port of Rails' Mutations::Integrations::Hubspot::SyncInvoice
 * (app/graphql/mutations/integrations/hubspot/sync_invoice.rb): "Sync
 * hubspot integration invoice" — the visible-invoice lookup feeds the
 * aggregator's async invoice push.
 *
 * TODO(port): the REQUIRED_PERMISSION gate
 * ("organization:integrations:update").
 * TODO(port): Integrations::Hubspot::Invoices::DeployPropertiesService —
 * the ported Hubspot deploy services cover companies/contacts only; the
 * generic invoice job runs instead.
 */
class SyncHubspotIntegrationInvoice
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        // Rails: current_organization.invoices.visible.find_by(id:).
        $invoice = Invoice::query()
            ->where('organization_id', LagoContext::currentOrganization($context)->id)
            ->visible()
            ->where('id', $input['invoiceId'] ?? null)
            ->first();

        if ($invoice === null) {
            throw Errors::notFoundError('invoice');
        }

        $result = (new CreateService($invoice))->call_async();

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return ['invoiceId' => $result->invoice_id];
    }
}
