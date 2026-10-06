<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\IntegrationCollectionMappings\CreateService;

/**
 * Port of Rails' Mutations::IntegrationCollectionMappings::Create
 * (app/graphql/mutations/integration_collection_mappings/create.rb):
 * "Create integration collection mapping". The currencies input list is
 * converted to the code → external code hash (Rails' input `prepare:`) and
 * checked for duplicate currency codes (the UniqueByFieldValidator).
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:update").
 */
class CreateIntegrationCollectionMapping
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = Args::snakeKeys(Args::input($args));

        $result = CreateService::call(params: $this->preparedInput($input));

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->integration_collection_mapping;
    }

    /**
     * Rails: BaseInput#convert_currencies_to_hash + the UniqueByFieldValidator
     * on :currency_code ("duplicated_field").
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function preparedInput(array $input): array
    {
        $currencies = $input['currencies'] ?? null;

        if (is_array($currencies)) {
            $codes = array_map(
                fn (array $item) => $item['currency_code'] ?? null,
                $currencies,
            );

            if (count($codes) !== count(array_unique($codes))) {
                throw Errors::executionError(
                    error: 'duplicated_field',
                    code: 'duplicated_field',
                );
            }

            $input['currencies'] = array_column($currencies, 'currency_external_code', 'currency_code');
        }

        return $input;
    }
}
