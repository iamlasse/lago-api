<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\DataExports\CreateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::DataExports::CreditNotes::Create
 * (app/graphql/mutations/data_exports/credit_notes/create.rb): "Request
 * data export of credit notes".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("credit_notes:export") lands
 * with the roles/Permission slice.
 */
class CreateCreditNotesDataExport
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = Args::snakeKeys(Args::input($args));

        $result = CreateService::call(
            organization: LagoContext::currentOrganization($context),
            user: LagoContext::currentUser($context),
            format: (string) ($input['format'] ?? 'csv'),
            resourceType: (string) ($input['resource_type'] ?? 'credit_notes'),
            resourceQuery: is_array($input['filters'] ?? null) ? $input['filters'] : [],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->data_export;
    }
}
