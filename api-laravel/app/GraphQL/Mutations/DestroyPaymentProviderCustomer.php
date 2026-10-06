<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Models\PaymentProviderCustomer;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\PaymentProviderCustomers\DestroyService;

/**
 * Port of Rails' Mutations::PaymentProviderCustomers::Destroy
 * (app/graphql/mutations/payment_provider_customers/destroy.rb): "Deletes a
 * payment provider customer connection" — the payload is {id}.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("customers:update") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class DestroyPaymentProviderCustomer
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $providerCustomer = PaymentProviderCustomer::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = DestroyService::call(paymentProviderCustomer: $providerCustomer);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return [
            'id' => $result->payment_provider_customer?->id,
            'client_mutation_id' => $args['input']['clientMutationId'] ?? null,
        ];
    }
}
