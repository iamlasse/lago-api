<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\InvoiceCustomSections\CreateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::InvoiceCustomSections::Create
 * (app/graphql/mutations/invoice_custom_sections/create.rb):
 * "Creates a new InvoiceCustomSection" — the whole input goes to
 * InvoiceCustomSections::CreateService merged with the current organization.
 */
class CreateInvoiceCustomSection
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $result = CreateService::call(organization: $organization, createParams: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->invoice_custom_section;
    }
}
