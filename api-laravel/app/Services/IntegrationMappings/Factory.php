<?php

declare(strict_types=1);

namespace App\Services\IntegrationMappings;

use LogicException;
use App\Models\Integration;

/**
 * Port of Rails' IntegrationMappings::Factory (app/services/
 * integration_mappings/factory.rb) — the STI subclass resolution.
 */
class Factory
{
    public static function new_instance(Integration $integration): string
    {
        return self::service_class($integration);
    }

    /** @return class-string<\App\Models\IntegrationMappings\BaseMapping> */
    public static function service_class(Integration $integration): string
    {
        return match ($integration->type) {
            'Integrations::NetsuiteIntegration' => \App\Models\IntegrationMappings\NetsuiteMapping::class,
            'Integrations::AnrokIntegration' => \App\Models\IntegrationMappings\AnrokMapping::class,
            'Integrations::AvalaraIntegration' => \App\Models\IntegrationMappings\AvalaraMapping::class,
            'Integrations::XeroIntegration' => \App\Models\IntegrationMappings\XeroMapping::class,
            default => throw new LogicException('NotImplementedError'),
        };
    }
}
