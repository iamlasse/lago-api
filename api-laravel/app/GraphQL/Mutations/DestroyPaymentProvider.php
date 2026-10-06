<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\PaymentProviders\DestroyService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::PaymentProviders::Destroy
 * (app/graphql/mutations/payment_providers/destroy.rb): "Destroy a payment
 * provider" — PaymentProviders\DestroyService soft-deletes the provider and
 * its connections; the payload is {id}.
 *
 * TODO(port): the REQUIRED_PERMISSION gate
 * ("organization:integrations:delete") lands with the roles/Permission
 * slice (context permissions are not populated yet).
 */
class DestroyPaymentProvider
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.payment_providers.find_by(id:).
        $paymentProvider = $organization->paymentProviders()->find($input['id'] ?? null);

        $result = DestroyService::call(paymentProvider: $paymentProvider);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return [
            'id' => $result->payment_provider?->id,
            'client_mutation_id' => $args['input']['clientMutationId'] ?? null,
        ];
    }
}
