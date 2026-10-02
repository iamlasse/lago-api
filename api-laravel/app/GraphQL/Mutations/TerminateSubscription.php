<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Subscriptions\TerminateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Subscriptions::Terminate
 * (app/graphql/mutations/subscriptions/terminate.rb): "Terminate a
 * Subscription" — the termination behaviors are forwarded only when present
 * (Rails' `args.compact`).
 */
class TerminateSubscription
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.subscriptions.find_by(id:)
        $subscription = $organization->subscriptions()->find($input['id'] ?? null);

        $result = TerminateService::call(
            subscription: $subscription,
            onTerminationCreditNote: $input['on_termination_credit_note'] ?? null,
            onTerminationInvoice: $input['on_termination_invoice'] ?? null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->subscription;
    }
}
