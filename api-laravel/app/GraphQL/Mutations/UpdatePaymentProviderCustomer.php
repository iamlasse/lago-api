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
use App\Services\PaymentProviderCustomers\UpdateConnectionService;

/**
 * Port of Rails' Mutations::PaymentProviderCustomers::Update
 * (app/graphql/mutations/payment_provider_customers/update.rb): "Updates a
 * payment provider customer connection" — the connection is scoped to the
 * organization's PaymentProviderCustomers::BaseCustomer rows.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("customers:update") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class UpdatePaymentProviderCustomer
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: PaymentProviderCustomers::BaseCustomer.where(organization_id:)
        // .find_by(id:).
        $providerCustomer = PaymentProviderCustomer::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = UpdateConnectionService::call(
            paymentProviderCustomer: $providerCustomer,
            params: collect($input)->except('id')->all(),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->payment_provider_customer;
    }
}
