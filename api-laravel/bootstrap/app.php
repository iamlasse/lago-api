<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use App\Exceptions\Api\ApiException;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\SetBetaHeader;
use Illuminate\Foundation\Application;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            // Health Check status (Rails: config/routes.rb)
            Route::get('/health', [HealthController::class, 'health']);
            Route::get('/ready', [HealthController::class, 'ready']);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'lago.auth' => AuthenticateApiKey::class,
            'lago.beta' => SetBetaHeader::class,
        ]);

        // Rails parity on the REST surface: Rails params are raw — strings
        // are neither trimmed nor collapsed to null ("" stays "", "\u0000"
        // reaches the model, which strips it). Skip both transforms for
        // api/*; web/GraphQL keep the Laravel defaults.
        $middleware->trimStrings([fn (Request $request) => $request->is('api/*')]);
        $middleware->convertEmptyStringsToNull([fn (Request $request) => $request->is('api/*')]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // The Lago error envelope: HTTP status matches the body's `status`.
        $exceptions->render(function (ApiException $exception, Request $request) {
            $response = response()->json($exception->body(), $exception->statusCode());

            // Every /api/v2/* response carries the beta header, errors
            // included (Rails prepends set_beta_header! in BaseController).
            if ($request->is('api/v2/*')) {
                $response->headers->set('X-Lago-Endpoint-Status', 'beta');
            }

            return $response;
        });

        // Catch-all unmatched API route (Rails: get "*path" ->
        // ApplicationController#not_found). Every /api/v2/* response carries
        // the beta header, unmatched routes included (Rails prepends
        // set_beta_header! in Api::BaseController).
        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $response = response()->json([
                'status' => 404,
                'error' => 'Not Found',
                'code' => 'resource_not_found',
            ], 404);

            if ($request->is('api/v2/*')) {
                $response->headers->set('X-Lago-Endpoint-Status', 'beta');
            }

            return $response;
        });
    })->create();
