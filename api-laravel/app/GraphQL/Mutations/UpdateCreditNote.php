<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\CreditNotes\UpdateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::CreditNotes::Update
 * (app/graphql/mutations/credit_notes/update.rb): "Updates an existing
 * Credit Note" — the credit note is looked up among the organization's
 * NOT-DELETED credit notes (Rails: credit_notes.not_deleted), so unknown or
 * deleted ids reach the service as nil and answer with the not_found
 * envelope.
 *
 * TODO(port): the `metadata` input is accepted but dropped until the
 * Metadata::ItemMetadata model is ported.
 */
class UpdateCreditNote
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $creditNote = \App\Models\CreditNote::query()
            ->where('organization_id', $organization->id)
            ->where('status', '!=', \App\Enums\CreditNoteStatus::Deleted->value)
            ->find($input['id'] ?? null);

        $params = [];
        if (array_key_exists('refund_status', $input)) {
            $params['refund_status'] = $input['refund_status'];
        }
        if (array_key_exists('metadata', $input)) {
            $params['metadata'] = $input['metadata'];
        }

        $result = UpdateService::call(
            creditNote: $creditNote,
            params: $params,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->credit_note;
    }
}
