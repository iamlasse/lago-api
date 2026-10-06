<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Invoices\LoseDisputeService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Invoices::LoseDispute
 * (app/graphql/mutations/invoices/lose_dispute.rb): "Mark payment dispute as
 * lost" — Invoices\LoseDisputeService stamps payment_dispute_lost_at on the
 * visible invoice.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("invoices:update") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class LoseInvoiceDispute
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.invoices.visible.find_by(id: args[:id]).
        $invoice = $organization->invoices()->visible()->find($input['id'] ?? null);

        $result = LoseDisputeService::call(
            invoice: $invoice,
            paymentDisputeLostAt: now()->toDateTimeString(),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->invoice;
    }
}
