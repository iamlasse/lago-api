<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Enums\InvoiceStatus;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Models\Invoice as InvoiceModel;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::InvoiceResolver
 * (app/graphql/resolvers/invoice_resolver.rb): "Query a single Invoice of an
 * organization" — `current_organization.invoices.visible.find(id)`, so the
 * invisible statuses (generating, open, closed, deleted) answer with the
 * not_found envelope exactly like Rails. Rails' `context.scoped_set!(
 * preserve_add_on_fee_period_dates, true)` concerns the Fee type's period
 * dates and has no ported consumer yet.
 */
class Invoice
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?InvoiceModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        /** @var \App\Models\Organization $organization */
        $organization = LagoContext::currentOrganization($context);

        $found = $organization
            ->invoices()
            ->whereIn('status', [
                InvoiceStatus::Draft->value,
                InvoiceStatus::Finalized->value,
                InvoiceStatus::Voided->value,
                InvoiceStatus::Failed->value,
                InvoiceStatus::Pending->value,
            ])
            ->find($args['id'] ?? null);

        if ($found === null) {
            throw Errors::notFoundError('invoice');
        }

        return $found;
    }
}
