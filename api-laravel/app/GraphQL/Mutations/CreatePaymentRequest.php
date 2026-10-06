<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\PaymentRequests\CreateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::PaymentRequests::Create
 * (app/graphql/mutations/payment_requests/create.rb): "Creates a payment
 * request" — PaymentRequests\CreateService builds the premium dunning
 * payment link over the overdue invoices.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("payments:create") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class CreatePaymentRequest
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $result = CreateService::call(organization: $organization, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->payment_request;
    }
}
