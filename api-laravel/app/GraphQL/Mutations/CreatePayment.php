<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Payments\ManualCreateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Payments::Create
 * (app/graphql/mutations/payments/create.rb): "Creates a manual payment" —
 * Payments\ManualCreateService records the manual payment on the invoice
 * and updates the invoice's paid/payment status.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("payments:create") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class CreatePayment
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $result = ManualCreateService::call(organization: $organization, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        // Rails: result.payment — nil for the silently-accepted advance
        // charges invoices.
        return $result->payment;
    }
}
