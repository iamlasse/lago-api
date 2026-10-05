<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Quote;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Quotes\UpdateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Quotes::Update (app/graphql/mutations/quotes/
 * update.rb): "Update a quote" — the owners sync.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("quotes:update").
 */
class UpdateQuote
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $quote = Quote::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = UpdateService::call(
            quote: $quote,
            params: array_diff_key($input, ['id' => true]),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->quote;
    }
}
