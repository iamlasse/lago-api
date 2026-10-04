<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Features;

use App\Models\Feature;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\ApiController;
use App\Serializers\V1\Entitlement\FeatureSerializer;
use App\Services\Entitlements\PrivilegeDestroyService;

/**
 * Port of Rails' Api::V1::Features::PrivilegesController
 * (app/controllers/api/v1/features/privileges_controller.rb) — the nested
 * DELETE /features/:feature_code/privileges/:code.
 */
class PrivilegesController extends ApiController
{
    protected ?string $resourceName = 'feature';

    public function destroy(Request $request): JsonResponse
    {
        $feature = Feature::query()
            ->where('organization_id', (string) $this->currentOrganization()->id)
            ->where('code', $request->route('feature_code'))
            ->first();

        if ($feature === null) {
            throw new NotFoundException('feature');
        }

        $privilege = $feature->privileges()
            ->where('code', $request->route('code'))
            ->first();

        if ($privilege === null) {
            throw new NotFoundException('privilege');
        }

        $result = PrivilegeDestroyService::call(privilege: $privilege);

        if ($result->success()) {
            return $this->renderSerializerJson((new FeatureSerializer(
                $feature,
                ['root_name' => 'feature'],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }
}
