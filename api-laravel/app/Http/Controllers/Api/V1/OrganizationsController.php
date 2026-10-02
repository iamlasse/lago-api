<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Firebase\JWT\JWT;
use RuntimeException;
use OpenSSLAsymmetricKey;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Api\ApiController;
use App\Services\Organizations\UpdateService;
use App\Serializers\V1\OrganizationSerializer;
use App\Exceptions\Api\ParameterMissingException;

/**
 * Port of Rails' Api::V1::OrganizationsController (app/controllers/api/v1/
 * organizations_controller.rb). GET /organizations shows the CURRENT
 * organization (there is no index/create — the api key scopes everything to
 * the authenticated organization).
 */
class OrganizationsController extends ApiController
{
    protected ?string $resourceName = 'organization';

    public function show(): JsonResponse
    {
        return $this->renderSerializerJson((new OrganizationSerializer(
            $this->currentOrganization(),
            ['root_name' => 'organization', 'includes' => ['taxes']],
        ))->toJson());
    }

    public function update(Request $request): JsonResponse
    {
        $params = $this->inputParams($request);

        $result = UpdateService::call(
            organization: $this->currentOrganization(),
            params: $params,
        );

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).
            return $this->renderSerializerJson((new OrganizationSerializer(
                $result->organization,
                ['root_name' => 'organization', 'includes' => ['taxes']],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    /**
     * Port of the grpc_token action: signs `{organization_id, aud:
     * "lago-grpc"}` with the RSA private key (RS256) — the token the gRPC
     * event store verifies with the matching public key.
     */
    public function grpcToken(): JsonResponse
    {
        $token = JWT::encode(
            [
                'organization_id' => $this->currentOrganization()->id,
                'aud' => 'lago-grpc',
            ],
            $this->rsaPrivateKey(),
            'RS256',
        );

        return response()->json([
            'organization' => [
                'grpc_token' => $token,
            ],
        ]);
    }

    /**
     * Port of `params.require(:organization).permit(...)` — Rails' permitted
     * update params, verbatim.
     *
     * @return array<string, mixed>
     */
    private function inputParams(Request $request): array
    {
        /** @var mixed $organization */
        $organization = $this->requireParam($request, 'organization');

        if (! is_array($organization)) {
            throw new ParameterMissingException('organization');
        }

        return $this->permitParams($organization, [
            'country',
            'default_currency',
            'address_line1',
            'address_line2',
            'state',
            'zipcode',
            'email',
            'city',
            'legal_name',
            'legal_number',
            'net_payment_term',
            'tax_identification_number',
            'timezone',
            'webhook_url',
            'document_numbering',
            'document_number_prefix',
            'finalize_zero_amount_invoice',
            'slug',
            'email_settings' => [],
            'billing_configuration' => [
                'invoice_footer',
                'invoice_grace_period',
                'document_locale',
            ],
        ]);
    }

    /**
     * Port of the RsaPrivateKey initializer (config/initializers/rsa_keys.rb):
     * config/keys/private.pem is not used by the port — the key comes from
     * `LAGO_RSA_PRIVATE_KEY` (PEM as-is, or Base64-decoded DER like Rails).
     */
    private function rsaPrivateKey(): OpenSSLAsymmetricKey
    {
        $material = config('lago.rsa_private_key') ?? env('LAGO_RSA_PRIVATE_KEY');

        if ($material === null || $material === '') {
            throw new RuntimeException(
                'Error: Private key is blank, you must provide a private key to start the application. Exiting...',
            );
        }

        $pem = str_contains((string) $material, '-----BEGIN')
            ? (string) $material
            : base64_decode((string) $material, true);

        $key = $pem === false ? false : openssl_pkey_get_private($pem);

        if ($key === false) {
            throw new RuntimeException('Could not parse the RSA private key');
        }

        return $key;
    }
}
