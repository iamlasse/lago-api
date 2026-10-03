<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Enums\CreditNoteStatus;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Models\CreditNote as CreditNoteModel;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::CreditNoteResolver
 * (app/graphql/resolvers/credit_note_resolver.rb): "Query a single credit
 * note" — `current_organization.credit_notes.finalized.find(id)`, so draft
 * and deleted credit notes answer with the not_found envelope exactly like
 * Rails.
 */
class CreditNote
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?CreditNoteModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        /** @var \App\Models\Organization $organization */
        $organization = LagoContext::currentOrganization($context);

        $found = CreditNoteModel::query()
            ->where('organization_id', $organization->id)
            ->where('status', CreditNoteStatus::Finalized->value)
            ->find($args['id'] ?? null);

        if ($found === null) {
            throw Errors::notFoundError('credit_note');
        }

        return $found;
    }
}
