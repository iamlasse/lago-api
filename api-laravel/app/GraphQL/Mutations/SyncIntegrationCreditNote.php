<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\CreditNote;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\Integrations\Aggregator\CreditNotes\CreateService;

/**
 * Port of Rails' Mutations::Integrations::SyncCreditNote
 * (app/graphql/mutations/integrations/sync_credit_note.rb): "Sync
 * integration credit note" — the aggregator's async credit note push.
 *
 * TODO(port): the REQUIRED_PERMISSION gate
 * ("organization:integrations:update").
 */
class SyncIntegrationCreditNote
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        // Rails: current_organization.credit_notes.not_deleted.find_by(id:)
        // — the port's CreditNote model soft-deletes, so the default scope
        // already excludes the deleted rows.
        $creditNote = CreditNote::query()
            ->where('organization_id', LagoContext::currentOrganization($context)->id)
            ->where('id', $input['creditNoteId'] ?? null)
            ->first();

        $result = (new CreateService($creditNote))->call_async();

        // Rails quirk kept: the ternary's result_error branch evaluates on
        // failure (the not_found envelope), then the raw result is what
        // backs the payload — a nil credit_note_id only on the error path.
        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return ['creditNoteId' => $result->credit_note_id];
    }
}
