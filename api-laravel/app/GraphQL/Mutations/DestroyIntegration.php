<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Integration;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Integrations\DestroyService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Integrations::Destroy
 * (app/graphql/mutations/integrations/destroy.rb): "Destroy an
 * integration" — the generic destroy service (the Okta/Entra specializations
 * live in the SSO slice; the payload is the destroyed row's id).
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:delete").
 */
class DestroyIntegration
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $integration = Integration::query()
            ->where('organization_id', $organization->id)
            ->find($args['id'] ?? null);

        $result = DestroyService::call(integration: $integration);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return ['id' => $result->integration->id];
    }
}
