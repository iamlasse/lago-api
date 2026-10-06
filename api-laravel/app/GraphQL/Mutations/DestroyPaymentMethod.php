<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\PaymentMethods\DestroyService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::PaymentMethods::Destroy
 * (app/graphql/mutations/payment_methods/destroy.rb): "Deletes a payment
 * method" — PaymentMethods\DestroyService soft-deletes the method and
 * clears its default flag; the payload is {id}.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("payment_methods:delete") lands
 * with the roles/Permission slice (context permissions are not populated
 * yet).
 */
class DestroyPaymentMethod
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.payment_methods.find_by(id:).
        $paymentMethod = $organization->paymentMethods()->find($input['id'] ?? null);

        $result = DestroyService::call(paymentMethod: $paymentMethod);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return [
            'id' => $result->payment_method?->id,
            'client_mutation_id' => $args['input']['clientMutationId'] ?? null,
        ];
    }
}
