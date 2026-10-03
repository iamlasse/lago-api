<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\Enums\CreditNoteStatus;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\CreditNotes\VoidService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Models\CreditNote as CreditNoteModel;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::CreditNotes::Void
 * (app/graphql/mutations/credit_notes/void.rb): "Voids a Credit Note" — the
 * credit note is looked up among the organization's NOT-DELETED credit
 * notes (Rails: credit_notes.not_deleted), so unknown or deleted ids reach
 * the service as nil and answer with the not_found envelope.
 */
class VoidCreditNote
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $creditNote = CreditNoteModel::query()
            ->where('organization_id', $organization->id)
            ->where('status', '!=', CreditNoteStatus::Deleted->value)
            ->find($input['id'] ?? null);

        $result = VoidService::call(creditNote: $creditNote);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->credit_note;
    }
}
