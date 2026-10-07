<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\InvoiceCustomSections\DestroyService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::InvoiceCustomSections::Destroy
 * (app/graphql/mutations/invoice_custom_sections/destroy.rb):
 * "Deletes an invoice_custom_section" — {id} payload (discarded section).
 */
class DestroyInvoiceCustomSection
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $invoiceCustomSection = $organization->invoiceCustomSections()
            ->find(Args::uuidOrNull($input['id'] ?? null));

        $result = DestroyService::call(invoiceCustomSection: $invoiceCustomSection);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->invoice_custom_section;
    }
}
