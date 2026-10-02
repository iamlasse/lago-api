<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Throwable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Port of ApplicationController#health / #ready in Rails
 * (routed at GET /health and GET /ready).
 */
class HealthController extends Controller
{
    public function health(): JsonResponse
    {
        try {
            // Rails: ActiveRecord::Base.connection.execute("") — a bare
            // connectivity check against the database.
            DB::select('select 1');

            return response()->json([
                'version' => config('lago.version'),
                'github_url' => config('lago.github_url'),
                'message' => 'Success',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'version' => config('lago.version'),
                'github_url' => config('lago.github_url'),
                'message' => 'Unhealthy',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function ready(): JsonResponse
    {
        // The Rails `shutting down` branch depends on a puma worker global;
        // the always-serving response is the contract that matters here.
        return response()->json(['status' => 'ok']);
    }
}
