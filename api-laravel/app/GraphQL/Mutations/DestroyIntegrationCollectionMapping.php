<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\IntegrationCollectionMappings\BaseCollectionMapping;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\IntegrationCollectionMappings\DestroyService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::IntegrationCollectionMappings::Destroy
 * (app/graphql/mutations/integration_collection_mappings/destroy.rb):
 * "Destroy an integration collection mapping" — the payload carries the
 * deleted row's id.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:update").
 */
class DestroyIntegrationCollectionMapping
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = Args::snakeKeys(Args::input($args));
        $organization = LagoContext::currentOrganization($context);

        // Rails: BaseCollectionMapping.joins(:integration).where(id:).where(
        // integration: {organization: current_organization}).first.
        $mapping = BaseCollectionMapping::query()
            ->join('integrations', 'integrations.id', '=', 'integration_collection_mappings.integration_id')
            ->where('integrations.organization_id', $organization->id)
            ->where('integration_collection_mappings.id', \App\GraphQL\Support\Args::uuidOrNull($input['id'] ?? null))
            ->select('integration_collection_mappings.*')
            ->first();

        $result = DestroyService::call(integration_collection_mapping: $mapping);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->integration_collection_mapping;
    }
}
