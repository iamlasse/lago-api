<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Page;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Invoices\Payments\RetryBatchService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Invoices::RetryAllPayments
 * (app/graphql/mutations/invoices/retry_all_payments.rb): "Retry all
 * invoice payments" — Invoices\Payments\RetryBatchService#call_async
 * enqueues the batch job and answers the eligible invoice list (Rails wraps
 * it in Kaminari.paginate_array for the collection shape).
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("invoices:update") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class RetryAllInvoicePayments
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $result = (new RetryBatchService(organizationId: $organization->id))->callAsync();

        /** @var \Illuminate\Support\Collection<int, object> $invoices */
        $invoices = $result->invoices ?? collect();

        $count = $invoices->count();

        return new Page(
            collection: $invoices->all(),
            metadata: (object) [
                'currentPage' => 1,
                'limitValue' => max(1, $count),
                'totalPages' => 1,
                'totalCount' => $count,
            ],
        );
    }
}
