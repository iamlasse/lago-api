<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Customer;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Customers\GenerateCheckoutUrlService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::PaymentMethods::GenerateCheckoutUrl
 * (app/graphql/mutations/payment_methods/generate_checkout_url.rb):
 * "Generates checkout url for payment method" — the organization's customer
 * goes to Customers\GenerateCheckoutUrlService; the payload is
 * {checkout_url, clientMutationId}.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("payment_methods:create") lands
 * with the roles/Permission slice (context permissions are not populated
 * yet).
 */
class GenerateCheckoutUrl
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.customers.find_by(id: args[:customer_id]).
        $customer = $organization->customers()->find($input['customer_id'] ?? null);

        $result = GenerateCheckoutUrlService::call(customer: $customer);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return [
            'checkout_url' => $result->checkout_url,
            'client_mutation_id' => $args['input']['clientMutationId'] ?? null,
        ];
    }
}
