<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\PaymentMethods\SetAsDefaultService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::PaymentMethods::SetAsDefault
 * (app/graphql/mutations/payment_methods/set_as_default.rb): "Set payment
 * method as default" — PaymentMethods\SetAsDefaultService clears the
 * customer's other defaults and flags this method.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("payment_methods:update") lands
 * with the roles/Permission slice (context permissions are not populated
 * yet).
 */
class SetPaymentMethodAsDefault
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.payment_methods.find_by(id: args[:id]).
        $paymentMethod = $organization->paymentMethods()->find($input['id'] ?? null);

        $result = SetAsDefaultService::call(paymentMethod: $paymentMethod);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->payment_method;
    }
}
