<?php

declare(strict_types=1);

namespace App\GraphQL\Unions;

use App\Models\Integration as IntegrationModel;
use App\GraphQL\Interfaces\AbstractLagoTypeResolver;
use GraphQL\Type\Definition\Type;

/**
 * Type resolver for the frozen SDL's `union Integration` — dispatches on
 * the stored STI type string (Rails' Types::Integrations::Object
 * .resolve_type).
 */
class Integration extends AbstractLagoTypeResolver
{
    public function __invoke(mixed $root): Type
    {
        $type = $root instanceof IntegrationModel ? (string) $root->type : null;

        return match ($type) {
            'Integrations::AnrokIntegration' => $this->type('AnrokIntegration'),
            'Integrations::AvalaraIntegration' => $this->type('AvalaraIntegration'),
            'Integrations::EntraIdIntegration' => $this->type('EntraIdIntegration'),
            'Integrations::HubspotIntegration' => $this->type('HubspotIntegration'),
            'Integrations::NetsuiteIntegration' => $this->type('NetsuiteIntegration'),
            'Integrations::OktaIntegration' => $this->type('OktaIntegration'),
            'Integrations::SalesforceIntegration' => $this->type('SalesforceIntegration'),
            'Integrations::XeroIntegration' => $this->type('XeroIntegration'),
            default => parent::__invoke($root),
        };
    }
}
