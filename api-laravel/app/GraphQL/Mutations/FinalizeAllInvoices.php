<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Page;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Invoices\FinalizeBatchService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Invoices::FinalizeAll
 * (app/graphql/mutations/invoices/finalize_all.rb): "Finalize all draft
 * invoices" — Invoices\FinalizeBatchService#call_async enqueues the batch
 * job and answers the DRAFT invoice list (Rails wraps it in
 * Kaminari.paginate_array for the collection shape).
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("invoices:update") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class FinalizeAllInvoices
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $result = (new FinalizeBatchService(organization: $organization))->callAsync();

        /** @var \Illuminate\Support\Collection<int, object> $invoices */
        $invoices = $result->invoices ?? collect();

        // Rails: Kaminari.paginate_array(result.invoices) — a one-page
        // collection of the whole batch.
        $count = $invoices instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator
            ? $invoices->total()
            : $invoices->count();

        return new Page(
            collection: $invoices->all(),
            metadata: (object) [
                'currentPage' => 1,
                'limitValue' => max(1, $count),
                'totalPages' => 1,
                'totalCount' => $count,
                'totalCountCapped' => false,
                'hasNextPage' => false,
            ],
        );
    }
}
