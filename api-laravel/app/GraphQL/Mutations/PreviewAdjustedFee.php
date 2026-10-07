<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\AdjustedFees\EstimateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::AdjustedFees::Preview
 * (app/graphql/mutations/adjusted_fees/preview.rb): "Preview Adjusted Fee"
 * — AdjustedFees::EstimateService; answers with the estimated FEE.
 */
class PreviewAdjustedFee
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $invoice = $organization->invoices()
            ->find(Args::uuidOrNull($input['invoice_id'] ?? null));

        $result = EstimateService::call(invoice: $invoice, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->fee;
    }
}
