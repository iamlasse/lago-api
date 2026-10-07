<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Models\InvoiceCustomSection as ModelsInvoiceCustomSection;

/**
 * Port of Rails' Resolvers::InvoiceCustomSectionResolver
 * (app/graphql/resolvers/invoice_custom_section_resolver.rb): "Query a
 * single invoice_custom_section of an organization".
 */
class InvoiceCustomSection
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ModelsInvoiceCustomSection
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $invoiceCustomSection = $organization->invoiceCustomSections()
            ->find(Args::uuidOrNull($args['id'] ?? null));

        if ($invoiceCustomSection === null) {
            throw Errors::notFoundError('invoice_custom_section');
        }

        return $invoiceCustomSection;
    }
}
