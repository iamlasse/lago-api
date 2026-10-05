<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Models\Integrations\XeroIntegration;
use App\Services\Integrations\Xero\UpdateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Integrations::Xero::Update
 * (app/graphql/mutations/integrations/xero/update.rb): "Update Xero
 * integration".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:update").
 */
class UpdateXeroIntegration
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $integration = XeroIntegration::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = UpdateService::call(integration: $integration, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->integration;
    }
}
