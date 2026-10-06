<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\IntegrationMappings\BaseMapping;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\IntegrationMappings\DestroyService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::IntegrationMappings::Destroy (app/graphql/
 * mutations/integration_mappings/destroy.rb): "Destroy an integration
 * mapping" — the payload carries the deleted row's id.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:update").
 */
class DestroyIntegrationMapping
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = Args::snakeKeys(Args::input($args));
        $organization = LagoContext::currentOrganization($context);

        // Rails: BaseMapping.joins(:integration).where(id:).where(integration:
        // {organization: current_organization}).first.
        $integrationMapping = BaseMapping::query()
            ->join('integrations', 'integrations.id', '=', 'integration_mappings.integration_id')
            ->where('integrations.organization_id', $organization->id)
            ->where('integration_mappings.id', $input['id'] ?? null)
            ->select('integration_mappings.*')
            ->first();

        $result = DestroyService::call(integration_mapping: $integrationMapping);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->integration_mapping;
    }
}
