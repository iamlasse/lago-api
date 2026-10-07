<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Invoice;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\AdjustedFees\CreateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::AdjustedFees::Create
 * (app/graphql/mutations/adjusted_fees/create.rb): "Creates Adjusted Fee" —
 * the invoice is looked up among the organization's invoices, the input goes
 * to AdjustedFees::CreateService. Answers with the adjusted FEE.
 */
class CreateAdjustedFee
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $invoice = $organization->invoices()
            ->find(Args::uuidOrNull($input['invoice_id'] ?? null));

        $result = CreateService::call(invoice: $invoice, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->fee;
    }
}
