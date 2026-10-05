<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\QuoteVersion;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\QuoteVersions\ApproveService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::QuoteVersions::Approve (app/graphql/mutations/
 * quote_versions/approve.rb): "Approve a quote version".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("quotes:approve").
 */
class ApproveQuoteVersion
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

        $result = ApproveService::call(
            quoteVersion: $quoteVersion,
            expiresAt: $input['expires_at'] ?? null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->quote_version;
    }
}
