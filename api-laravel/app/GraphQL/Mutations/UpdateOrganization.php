<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Support\TimezoneWire;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Organizations\UpdateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Organizations::Update
 * (app/graphql/mutations/organizations/update.rb): "Updates an Organization"
 * — hands the snake_cased input to Organizations::UpdateService and returns
 * the updated organization, or raises the mapped service failure.
 */
class UpdateOrganization
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // The TimezoneEnum carries TZ_* wire names; Rails' enum hands the
        // service the IANA identifier.
        if (array_key_exists('timezone', $input)) {
            $input['timezone'] = TimezoneWire::fromWire($input['timezone']);
        }

        $result = UpdateService::call(
            organization: $organization,
            params: Args::snakeKeys(Args::input($args)),
            user: LagoContext::currentUser($context),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->organization;
    }
}
