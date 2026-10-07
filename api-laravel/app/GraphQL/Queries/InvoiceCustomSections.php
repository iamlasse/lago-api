<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' Resolvers::InvoiceCustomSectionsResolver
 * (app/graphql/resolvers/invoice_custom_sections_resolver.rb): the
 * organization's MANUAL sections, ordered by name, kaminari-paginated.
 */
class InvoiceCustomSections
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        /** @var LengthAwarePaginator $paginator */
        $paginator = $organization->invoiceCustomSections()
            ->where('section_type', 'manual')
            ->orderBy('name')
            ->paginate(
                perPage: $args['limit'] ?? 10,
                page: $args['page'] ?? 1,
            );

        return Page::fromLengthAwarePaginator($paginator);
    }
}
