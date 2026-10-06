<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Invoice;
use App\Jobs\SendWebhookJob;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Integrations::Salesforce::SyncInvoice
 * (app/graphql/mutations/integrations/salesforce/sync_invoice.rb) and
 * Integrations::Salesforce::Invoices::SyncService
 * (app/services/integrations/salesforce/invoices/sync_service.rb) — "Sync
 * Salesforce integration invoice": the invoice.resynced webhook enqueue.
 *
 * TODO(port): the REQUIRED_PERMISSION gate
 * ("organization:integrations:update").
 */
class SyncSalesforceInvoice
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

        SendWebhookJob::performLater('invoice.resynced', $invoice);

        return ['invoiceId' => $invoice->id];
    }
}
