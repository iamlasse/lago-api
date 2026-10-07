<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Invoice;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\Invoices\BuildRegenerationPreviewService;

/**
 * Port of Rails' Resolvers::InvoiceBuildRegenerationPreviewResolver
 * (app/graphql/resolvers/invoice_build_regeneration_preview_resolver.rb):
 * "Build a preview of a single Invoice of an organization for
 * regeneration."
 */
class InvoiceBuildRegenerationPreview
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?Invoice
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $invoice = $organization->invoices()
            ->visible()
            ->find(Args::uuidOrNull($args['id'] ?? null));

        if ($invoice === null) {
            throw Errors::notFoundError('invoice');
        }

        $result = BuildRegenerationPreviewService::call(invoice: $invoice);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->invoice;
    }
}
