<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M1 scaffolding: exists only so the v1/v2 route groups, the api-key
 * middleware and the error envelopes are exercisable before the first
 * real controllers are ported. Mirrors the anonymous spec controller in
 * Rails' spec/requests/api/base_controller_spec.rb (GET renders bare
 * success, POST requires the `input` param).
 */
class PlaceholderController extends ApiController
{
    protected ?string $resourceName = 'organization';

    public function index(): JsonResponse
    {
        return response()->json(['placeholder' => true]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireParam($request, 'input');

        return response()->json(['placeholder' => true]);
    }
}
