<?php

declare(strict_types=1);

namespace App\Services\IntegrationCollectionMappings;

use LogicException;
use App\Models\Integration;

/**
 * Port of Rails' IntegrationCollectionMappings::Factory (app/services/
 * integration_collection_mappings/factory.rb) — the STI subclass resolution.
 */
class Factory
{
    public static function new_instance(Integration $integration): string
    {
        return self::service_class($integration);
    }

    /** @return class-string<\App\Models\IntegrationCollectionMappings\BaseCollectionMapping> */
    public static function service_class(Integration $integration): string
    {
        return match ($integration->type) {
            'Integrations::NetsuiteIntegration' => \App\Models\IntegrationCollectionMappings\NetsuiteCollectionMapping::class,
            'Integrations::AnrokIntegration' => \App\Models\IntegrationCollectionMappings\AnrokCollectionMapping::class,
            'Integrations::AvalaraIntegration' => \App\Models\IntegrationCollectionMappings\AvalaraCollectionMapping::class,
            'Integrations::XeroIntegration' => \App\Models\IntegrationCollectionMappings\XeroCollectionMapping::class,
            default => throw new LogicException('NotImplementedError'),
        };
    }
}
