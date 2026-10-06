<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\CreditNote;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Emails\ResendService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::CreditNotes::ResendEmail
 * (app/graphql/mutations/credit_notes/resend_email.rb): "Resend credit note
 * email with optional custom recipients" — Emails\ResendService on a
 * FINALIZED credit note; the payload is the credit note itself.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("credit_notes:send") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class ResendCreditNoteEmail
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.credit_notes.finalized.find_by(id:).
        $creditNote = CreditNote::query()
            ->where('organization_id', $organization->id)
            ->finalized()
            ->find($input['id'] ?? null);

        $result = ResendService::call(
            resource: $creditNote,
            to: $input['to'] ?? null,
            cc: $input['cc'] ?? null,
            bcc: $input['bcc'] ?? null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $creditNote ?? throw Errors::notFoundError('credit_note');
    }
}
