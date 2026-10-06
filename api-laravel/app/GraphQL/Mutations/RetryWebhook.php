<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Webhook;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Webhooks\RetryService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Webhooks::Retry
 * (app/graphql/mutations/webhooks/retry.rb): "Retry a Webhook".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("developers:manage").
 */
class RetryWebhook
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        // Rails: current_organization.webhooks.find_by(id:).
        $webhook = Webhook::query()
            ->where('organization_id', LagoContext::currentOrganization($context)->id)
            ->where('id', $input['id'] ?? null)
            ->first();

        $result = RetryService::call(webhook: $webhook);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->webhook;
    }
}
