<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\WebhookEndpoint;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Webhooks\Endpoints\DestroyService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::WebhookEndpoints::Destroy
 * (app/graphql/mutations/webhook_endpoints/destroy.rb): "Deletes a webhook
 * endpoint" — the payload carries the deleted row's id.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("developers:manage").
 */
class DestroyWebhookEndpoint
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        // Rails: current_organization.webhook_endpoints.find_by(id:).
        $endpoint = WebhookEndpoint::query()
            ->where('organization_id', LagoContext::currentOrganization($context)->id)
            ->where('id', $input['id'] ?? null)
            ->first();

        $result = DestroyService::call(webhookEndpoint: $endpoint);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->webhook_endpoint;
    }
}
