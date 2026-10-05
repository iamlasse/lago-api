<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use App\Exceptions\Api\ApiException;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\SetBetaHeader;
use Illuminate\Foundation\Application;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\AuthenticateApiKey;
use Nuwave\Lighthouse\Http\GraphQLController;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * Raw-kernel handles (contract replay) pass the unconverted Symfony request
 * to the middleware/exception closures — accept either request type.
 */
$asIlluminateRequest = fn ($request): Request => $request instanceof Request
    ? $request
    : Request::createFromBase($request);
$isApiPath = fn ($request): bool => $asIlluminateRequest($request)->is('api/*');
$isV2ApiPath = fn ($request): bool => $asIlluminateRequest($request)->is('api/v2/*');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            // Health Check status (Rails: config/routes.rb)
            Route::get('/health', [HealthController::class, 'health']);
            Route::get('/ready', [HealthController::class, 'ready']);

            // Provider webhooks (Rails: config/routes.rb `resources :webhooks`
            // — POST /webhooks/{provider}/{organization_id}, outside the api
            // namespace, no API-key auth).
            Route::prefix('webhooks')
                ->name('webhooks.')
                ->group(__DIR__.'/../routes/webhooks.php');

            // Alias of Lighthouse's `/graphql` (config/lighthouse.php
            // route.uri): the Lago front computes its endpoint as
            // `${apiUrl}/graphql`, and its apiUrl is
            // `https://${LAGO_DOMAIN}/api` whenever window.API_URL is missing
            // (front envGlobalVar.ts getApiUrl fallback) — so the engine must
            // answer at both shapes. Registered in `then:` (like Lighthouse's
            // own loadRoutesFrom) to keep the web group's CSRF off it; same
            // methods + middleware as Nuwave's registration.
            Route::match(['GET', 'POST'], 'api/graphql', GraphQLController::class)
                ->middleware(config('lighthouse.route.middleware') ?? [])
                ->name('graphql.api-alias');
        },
    )
    ->withMiddleware(function (Middleware $middleware) use ($isApiPath): void {
        $middleware->alias([
            'lago.auth' => AuthenticateApiKey::class,
            'lago.beta' => SetBetaHeader::class,
        ]);

        // Rails parity on the REST surface: Rails params are raw — strings
        // are neither trimmed nor collapsed to null ("" stays "", "\u0000"
        // reaches the model, which strips it). Skip both transforms for
        // api/*; web/GraphQL keep the Laravel defaults.
        $middleware->trimStrings([$isApiPath]);
        $middleware->convertEmptyStringsToNull([$isApiPath]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($asIlluminateRequest, $isApiPath, $isV2ApiPath): void {
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $isApiPath($request) || $asIlluminateRequest($request)->expectsJson(),
        );

        // The Lago error envelope: HTTP status matches the body's `status`.
        $exceptions->render(function (ApiException $exception, $request) use ($isV2ApiPath) {
            $response = response()->json($exception->body(), $exception->statusCode());

            // Every /api/v2/* response carries the beta header, errors
            // included (Rails prepends set_beta_header! in BaseController).
            if ($isV2ApiPath($request)) {
                $response->headers->set('X-Lago-Endpoint-Status', 'beta');
            }

            return $response;
        });

        // Catch-all unmatched API route (Rails: get "*path" ->
        // ApplicationController#not_found). Every /api/v2/* response carries
        // the beta header, unmatched routes included (Rails prepends
        // set_beta_header! in Api::BaseController).
        $exceptions->render(function (NotFoundHttpException $exception, $request) use ($isApiPath, $isV2ApiPath) {
            if (! $isApiPath($request)) {
                return null;
            }

            $response = response()->json([
                'status' => 404,
                'error' => 'Not Found',
                'code' => 'resource_not_found',
            ], 404);

            if ($isV2ApiPath($request)) {
                $response->headers->set('X-Lago-Endpoint-Status', 'beta');
            }

            return $response;
        });
    })->create();
