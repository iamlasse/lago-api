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
 * Port of Rails' Mutations::Integrations::SyncInvoice
 * (app/graphql/mutations/integrations/sync_invoice.rb): "Sync integration
 * invoice" — the aggregator's async invoice push.
 *
 * TODO(port): the REQUIRED_PERMISSION gate
 * ("organization:integrations:update").
 * TODO(port): the `find_first: true` flag (the reconciliation leg).
 */
class SyncIntegrationInvoice
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        // Rails: current_organization.invoices.find_by(id:) — the async
        // service answers not_found_failure!("invoice") for a miss.
        $invoice = Invoice::query()
            ->where('organization_id', LagoContext::currentOrganization($context)->id)
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
