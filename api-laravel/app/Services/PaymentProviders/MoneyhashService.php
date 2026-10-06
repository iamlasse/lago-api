<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders;

use App\Models\PaymentProvider;
use App\Services\BaseResult;
use Illuminate\Support\Facades\Http;

use function array_key_exists;

/**
 * Port of Rails' PaymentProviders::MoneyhashService#create_or_update
 * (app/services/payment_providers/moneyhash_service.rb) — find-or-new the
 * organization's moneyhash provider by id/code and assign the args (api_key
 * lives in secrets, flow_id in settings). A provider without a stored
 * signature key fetches it from the Moneyhash organizations endpoint.
 */
class MoneyhashService extends AbstractProviderService
{
    protected function slug(): string
    {
        return 'moneyhash';
    }

    protected function providerAttribute(): string
    {
        return 'moneyhash_provider';
    }

    public function execute(): BaseResult
    {
        $result = parent::execute();

        // Rails: the signature-key fetch failure surfaces as
        // service_failure!(code: "moneyhash_error") — parent::execute
        // already stored the provider, so mirror the failure payload here.
        if ($this->signatureKeyFailure !== null) {
            return $result->serviceFailure(
                code: 'moneyhash_error',
                message: $this->signatureKeyFailure,
            );
        }

        return $result;
    }

    /** @var string|null the formatted moneyhash_error message, when the fetch failed. */
    private ?string $signatureKeyFailure = null;

    /**
     * @param  array<string, mixed>  $args
     */
    protected function applyAttributes(PaymentProvider $provider, array $args): void
    {
        if (array_key_exists('api_key', $args)) {
            $this->pushToSecrets($provider, 'api_key', $args['api_key']);
        }

        if (array_key_exists('code', $args)) {
            $provider->code = $args['code'];
        }

        if (array_key_exists('name', $args)) {
            $provider->name = $args['name'];
        }

        if (array_key_exists('flow_id', $args)) {
            $provider->pushToSettings('flow_id', $args['flow_id']);
        }

        if (($provider->signatureKey() ?? '') === '') {
            $signatureKey = $this->getSignatureKey($provider);

            if ($signatureKey === null) {
                return;
            }

            $this->pushToSecrets($provider, 'signature_key', $signatureKey);
        }

        // Rails: moneyhash_provider.save(validate: false) — the model is
        // saved without re-running the (already-passed) validations.
    }

    /**
     * Rails: #get_signature_key — GET
     * /api/v1/organizations/get-webhook-signature-key/ with the api key;
     * an HTTP failure answers a moneyhash_error service failure.
     */
    private function getSignatureKey(PaymentProvider $provider): ?string
    {
        try {
            $response = Http::withHeaders([
                'X-Api-Key' => (string) $provider->apiKey(),
            ])->get(PaymentProvider::moneyhashApiBaseUrl().'/api/v1/organizations/get-webhook-signature-key/');

            if ($response->failed()) {
                $this->signatureKeyFailure = $response->status().': '.$response->body();

                return null;
            }

            return $response->json('data.webhook_signature_secret');
        } catch (\Throwable $e) {
            $this->signatureKeyFailure = $e->getMessage();

            return null;
        }
    }
}
