<?php

declare(strict_types=1);

namespace App\Services\Integrations\Hubspot\Contacts;

use App\Services\BaseResult;
use App\Http\Client\LagoHttpError;
use App\Models\Integrations\HubspotIntegration;

/**
 * Port of Rails' Integrations::Hubspot::Contacts::DeployPropertiesService
 * (…/hubspot/contacts/deploy_properties_service.rb) — idempotently deploys
 * the lago_* custom properties onto the HubSpot contacts object, version-
 * stamped in the integration settings.
 */
class DeployPropertiesService extends \App\Services\Integrations\Aggregator\BaseService
{
    public const int VERSION = 1;

    public function actionPath(): string
    {
        return 'v1/hubspot/properties';
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('response');

        $integration = $this->integration;

        if (! $integration instanceof HubspotIntegration) {
            return $result;
        }

        if ($integration->contactsPropertiesVersion() === self::VERSION) {
            return $result;
        }

        try {
            $response = $this->http_client()->postWithResponse($this->payload(), $this->headers());

            $settings = (array) ($integration->fresh()->settings ?? []);
            $settings['contacts_properties_version'] = self::VERSION;
            $integration->settings = $settings;
            $integration->save();

            $result->response = $response;

            return $result;
        } catch (LagoHttpError $e) {
            $message = $this->message($e);

            $this->deliver_integration_error_webhook('integration_error', $message);

            return $result;
        }
    }

    /**
     * Rails: the deploy headers — hardcoded hubspot provider config key.
     *
     * @return array<string, string|null>
     */
    protected function headers(): array
    {
        return [
            'Provider-Config-Key' => 'hubspot',
            'Authorization' => 'Bearer '.$this->secret_key(),
            'Connection-Id' => $this->integration->getFromSecrets('connection_id'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'objectType' => 'contacts',
            'inputs' => [
                [
                    'groupName' => 'contactinformation',
                    'name' => 'lago_customer_id',
                    'label' => 'Lago Customer Id',
                    'type' => 'string',
                    'fieldType' => 'text',
                    'displayOrder' => -1,
                    'hasUniqueValue' => true,
                    'searchableInGlobalSearch' => true,
                    'formField' => true,
                ],
                [
                    'groupName' => 'contactinformation',
                    'name' => 'lago_customer_external_id',
                    'label' => 'Lago Customer External Id',
                    'type' => 'string',
                    'fieldType' => 'text',
                    'displayOrder' => -1,
                    'searchableInGlobalSearch' => true,
                    'formField' => true,
                ],
                [
                    'groupName' => 'contactinformation',
                    'name' => 'lago_billing_email',
                    'label' => 'Lago Billing Email',
                    'type' => 'string',
                    'fieldType' => 'text',
                    'searchableInGlobalSearch' => true,
                    'formField' => true,
                ],
                [
                    'groupName' => 'contactinformation',
                    'name' => 'lago_customer_link',
                    'label' => 'Lago Customer Link',
                    'type' => 'string',
                    'fieldType' => 'text',
                    'searchableInGlobalSearch' => true,
                    'formField' => true,
                ],
            ],
        ];
    }
}
