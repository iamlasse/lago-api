<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\QuoteVersion;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\QuoteVersions\VoidService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::QuoteVersions::Void (app/graphql/mutations/
 * quote_versions/void.rb): "Void a quote version" — always with the manual
 * reason on this surface.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("quotes:void").
 */
class VoidQuoteVersion
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $quoteVersion = QuoteVersion::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = VoidService::call(
            quoteVersion: $quoteVersion,
            reason: 'manual',
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->quote_version;
    }
}
