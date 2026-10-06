<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\Models\BillingEntity;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\BillingEntities\Taxes\ApplyTaxesService;

/**
 * Port of Rails' Mutations::BillingEntities::ApplyTaxes
 * (app/graphql/mutations/billing_entities/apply_taxes.rb): applies the taxes
 * matching the given codes to a billing entity; the payload carries the
 * applied taxes.
 */
class BillingEntityApplyTaxes
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.billing_entities.find(billing_entity_id)
        // — an unknown id answers the not_found envelope for billing_entity.
        $billingEntity = BillingEntity::query()
            ->where('organization_id', $organization->id)
            ->find($input['billing_entity_id'] ?? null);

        if ($billingEntity === null) {
            throw Errors::notFoundError('billing_entity');
        }

        $result = ApplyTaxesService::call(
            billingEntity: $billingEntity,
            taxCodes: (array) ($input['tax_codes'] ?? []),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        // Rails: {applied_taxes: result.taxes_to_apply || []}.
        return ['applied_taxes' => $result->taxes_to_apply ?? []];
    }
}
