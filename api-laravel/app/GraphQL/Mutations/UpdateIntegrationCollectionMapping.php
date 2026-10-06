<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\IntegrationCollectionMappings\BaseCollectionMapping;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\IntegrationCollectionMappings\UpdateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::IntegrationCollectionMappings::Update
 * (app/graphql/mutations/integration_collection_mappings/update.rb):
 * "Update integration mapping". The currencies input list is converted to
 * the code → external code hash (Rails' input `prepare:`) and checked for
 * duplicate currency codes (the UniqueByFieldValidator).
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:update").
 */
class UpdateIntegrationCollectionMapping
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = $this->preparedInput(Args::input($args));
        $organization = LagoContext::currentOrganization($context);

        // Rails: BaseCollectionMapping.joins(:integration).find_by(id:,
        // integration: {organization: current_organization}).
        $mapping = BaseCollectionMapping::query()
            ->join('integrations', 'integrations.id', '=', 'integration_collection_mappings.integration_id')
            ->where('integrations.organization_id', $organization->id)
            ->where('integration_collection_mappings.id', $input['id'] ?? null)
            ->select('integration_collection_mappings.*')
            ->first();

        unset($input['id'], $input['integration_id'], $input['mapping_type']);

        $result = UpdateService::call(
            integration_collection_mapping: $mapping,
            params: Args::snakeKeys($input),
        );

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
                fn (array $item) => $item['currencyCode'] ?? null,
                $currencies,
            );

            if (count($codes) !== count(array_unique($codes))) {
                throw Errors::executionError(
                    error: 'duplicated_field',
                    code: 'duplicated_field',
                );
            }

            $input['currencies'] = array_column($currencies, 'currencyExternalCode', 'currencyCode');
        }

        return $input;
    }
}
