<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Models\IntegrationMappings\BaseMapping;
use App\Services\IntegrationMappings\UpdateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::IntegrationMappings::Update (app/graphql/
 * mutations/integration_mappings/update.rb): "Update integration mapping".
 * The integrationId / mappableId / mappableType input fields are deprecated
 * no-ops in Rails and are ignored here too.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:update").
 */
class UpdateIntegrationMapping
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = Args::snakeKeys(Args::input($args));
        $organization = LagoContext::currentOrganization($context);

        // Rails: BaseMapping.joins(:integration).where(id:, integration:
        // {organization: current_organization}).first.
        $integrationMapping = BaseMapping::query()
            ->join('integrations', 'integrations.id', '=', 'integration_mappings.integration_id')
            ->where('integrations.organization_id', $organization->id)
            ->where('integration_mappings.id', Args::uuidOrNull($input['id'] ?? null))
            ->select('integration_mappings.*')
            ->first();

        unset($input['id'], $input['integration_id'], $input['mappable_id'], $input['mappable_type']);

        $result = UpdateService::call(integration_mapping: $integrationMapping, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->integration_mapping;
    }
}
