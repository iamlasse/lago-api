<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\CreditNote;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\CreditNotes\GenerateXmlService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::CreditNotes::DownloadXml
 * (app/graphql/mutations/credit_notes/download_xml.rb): "Download a Credit
 * Note XML" — CreditNotes\GenerateXmlService (not_deleted lookup) and answer
 * the credit note (xml_url).
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("credit_notes:view") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class DownloadXmlCreditNote
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.credit_notes.not_deleted.find_by(id:).
        $creditNote = CreditNote::query()
            ->where('organization_id', $organization->id)
            // Rails: credit_notes.not_deleted — the status-column flag.
            ->where('status', '!=', \App\Enums\CreditNoteStatus::Deleted->value)
            ->find($input['id'] ?? null);

        $result = GenerateXmlService::call(creditNote: $creditNote);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->credit_note;
    }
}
