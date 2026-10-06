<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Tax;
use App\GraphQL\Support\Page;
use App\Models\BillingEntity;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::BillingEntityTaxesResolver
 * (app/graphql/resolvers/billing_entity_taxes_resolver.rb): "Query taxes of a
 * billing entity" — the organization's billing entity's applied taxes.
 */
class BillingEntityTaxes
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        // Rails: current_organization.billing_entities.find(billing_entity_id)
        // — an unknown id answers the not_found envelope for billing_entity.
        $billingEntity = BillingEntity::query()
            ->where('organization_id', $organization->id)
            ->find($args['billingEntityId'] ?? null);

        if ($billingEntity === null) {
            throw Errors::notFoundError('billing_entity');
        }

        // Rails: billing_entity.taxes — the taxes joined through
        // billing_entities_taxes (the join table has no model), wrapped in
        // the frozen SDL's TaxCollection shape.
        $taxes = Tax::query()
            ->whereIn('id', \Illuminate\Support\Facades\DB::table('billing_entities_taxes')
                ->where('billing_entity_id', $billingEntity->id)
                ->select('tax_id'))
            ->orderBy('name')
            ->get();

        return Page::fromLengthAwarePaginator(
            new \Illuminate\Pagination\LengthAwarePaginator($taxes, $taxes->count(), $taxes->count() ?: 1),
        );
    }
}
