<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Tax;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Taxes\UpdateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Taxes::Update (app/graphql/mutations/taxes/update.rb):
 * "Update an existing tax" — the tax is scoped to the current organization,
 * then the whole input goes to Taxes::UpdateService.
 */
class UpdateTax
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.taxes.find_by(id: args[:id]).
        $tax = Tax::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = UpdateService::call(tax: $tax, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->tax;
    }
}
