<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\InvoiceCustomSections\UpdateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::InvoiceCustomSections::Update
 * (app/graphql/mutations/invoice_custom_sections/update.rb):
 * "Updates an InvoiceCustomSection" — the section is looked up among the
 * organization's sections, the remaining input goes to
 * InvoiceCustomSections::UpdateService.
 */
class UpdateInvoiceCustomSection
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $invoiceCustomSection = $organization->invoiceCustomSections()
            ->find(Args::uuidOrNull($input['id'] ?? null));

        unset($input['id']);

        $result = UpdateService::call(
            invoiceCustomSection: $invoiceCustomSection,
            updateParams: $input,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->invoice_custom_section;
    }
}
