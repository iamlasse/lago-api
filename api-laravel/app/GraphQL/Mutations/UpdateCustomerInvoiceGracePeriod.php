<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Customers\UpdateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Customers::UpdateInvoiceGracePeriod
 * (app/graphql/mutations/customers/update_invoice_grace_period.rb) — Rails
 * marks the mutation as a TODO to remove (Customers::Update owns the grace
 * period now), but the frozen schema still serves it: it delegates to
 * Customers\UpdateService with the invoice_grace_period attribute.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("customers:update") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class UpdateCustomerInvoiceGracePeriod
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.customers.find_by(id:).
        $customer = $organization->customers()->find($input['id'] ?? null);

        $result = UpdateService::call(
            customer: $customer,
            args: ['invoice_grace_period' => $input['invoice_grace_period'] ?? null],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->customer;
    }
}
