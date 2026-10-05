<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\QuoteVersion;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\QuoteVersions\UpdateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::QuoteVersions::Update (app/graphql/mutations/
 * quote_versions/update.rb): "Update a quote version" — the draft's billing
 * items / content / currency / billing entity.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("quotes:update").
 */
class UpdateQuoteVersion
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        // NOTE: only the top-level input keys convert (graphql-ruby's
        // keyword conversion) — billing_items is a JSON scalar, its inner
        // payload stays in the camelCase of the frozen billing-items shape.
        $input = array_combine(
            array_map(fn (string $key): string => \Illuminate\Support\Str::snake($key), array_keys(Args::input($args))),
            array_values(Args::input($args)),
        );

        $quoteVersion = QuoteVersion::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = UpdateService::call(
            quoteVersion: $quoteVersion,
            params: array_diff_key($input, ['id' => true]),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->quote_version;
    }
}
