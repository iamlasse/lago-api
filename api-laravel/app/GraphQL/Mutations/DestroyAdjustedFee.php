<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Fee;
use App\Enums\InvoiceStatus;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\AdjustedFees\DestroyService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::AdjustedFees::Destroy
 * (app/graphql/mutations/adjusted_fees/destroy.rb): "Deletes an adjusted
 * fee" — the fee is looked up among the organization's DRAFT invoices'
 * fees; {id} payload.
 */
class DestroyAdjustedFee
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $fee = Fee::query()
            ->whereIn('invoice_id', $organization->invoices()
                ->where('status', InvoiceStatus::Draft->value)
                ->select('id'))
            ->find(Args::uuidOrNull($input['id'] ?? null));

        $result = DestroyService::call(fee: $fee);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->fee;
    }
}
