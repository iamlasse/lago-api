<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\IntegrationCollectionMappings\BaseCollectionMapping as BaseCollectionMappingModel;

/**
 * Field resolvers for the frozen SDL's `CollectionMapping` type (port of
 * Rails' Types::IntegrationCollectionMappings::Object) — `mappingType`
 * serializes the Rails enum name (not the stored integer), the settings
 * accessors surface as plain fields and `currencies` maps the settings hash
 * onto the CurrencyMappingItem shape.
 */
class CollectionMapping
{
    public function mappingType(BaseCollectionMappingModel $root): ?string
    {
        $raw = $root->getRawOriginal('mapping_type');

        return $raw === null ? null : BaseCollectionMappingModel::mappingTypeName((int) $raw);
    }

    public function externalId(BaseCollectionMappingModel $root): ?string
    {
        return $root->getFromSettings('external_id');
    }

    public function externalAccountCode(BaseCollectionMappingModel $root): ?string
    {
        return $root->getFromSettings('external_account_code');
    }

    public function externalName(BaseCollectionMappingModel $root): ?string
    {
        return $root->getFromSettings('external_name');
    }

    public function taxNexus(BaseCollectionMappingModel $root): ?string
    {
        return $root->getFromSettings('tax_nexus');
    }

    public function taxType(BaseCollectionMappingModel $root): ?string
    {
        return $root->getFromSettings('tax_type');
    }

    public function taxCode(BaseCollectionMappingModel $root): ?string
    {
        return $root->getFromSettings('tax_code');
    }

    /**
     * Rails: `object.currencies.map { |code, external| {currency_code:,
     * currency_external_code:} }`.
     *
     * @return list<array<string, string>>|null
     */
    public function currencies(BaseCollectionMappingModel $root): ?array
    {
        $currencies = $root->currencies;

        if ($currencies === null) {
            return null;
        }

        $output = [];

        foreach ($currencies as $currencyCode => $currencyExternalCode) {
            $output[] = [
                'currencyCode' => $currencyCode,
                'currencyExternalCode' => $currencyExternalCode,
            ];
        }

        return $output;
    }
}
