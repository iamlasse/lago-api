<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use LogicException;
use App\Models\ApiKey;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Exceptions\Api\NotFoundException;
use App\Exceptions\Api\ForbiddenException;
use App\Exceptions\Api\ValidationException;
use App\Exceptions\Api\UnauthorizedException;
use App\Exceptions\Api\MethodNotAllowedException;
use App\Exceptions\Api\ParameterMissingException;

/**
 * Port of the Api::BaseController controller state. Authentication,
 * context setting, usage tracking and authorization live in the
 * `lago.auth` middleware; subclasses declare which API resource they
 * serve (mirrors Rails' `def resource_name ... end` overrides) and get
 * `$this->currentOrganization` / `$this->currentApiKey` populated.
 */
abstract class ApiController extends Controller
{
    protected ?ApiKey $currentApiKey = null;

    protected ?Organization $currentOrganization = null;

    /**
     * Mirrors Rails' Api::BaseController#resource_name (nil by default;
     * subclasses override with e.g. "customers", "plans", "invoices").
     */
    protected ?string $resourceName = null;

    public function resourceName(): ?string
    {
        return $this->resourceName;
    }

    public function setCurrentApiKey(?ApiKey $apiKey): void
    {
        $this->currentApiKey = $apiKey;
    }

    public function setCurrentOrganization(?Organization $organization): void
    {
        $this->currentOrganization = $organization;
    }

    /**
     * Port of Api::BaseController#track_api_key_usage? (true by default).
     */
    public function trackApiKeyUsage(): bool
    {
        return true;
    }

    /**
     * Port of Api::BaseController#cached_api_key? — only opt-in controllers
     * serve the api key/organization pair from the cache.
     */
    public function cachedApiKey(): bool
    {
        return false;
    }

    protected function currentApiKey(): ?ApiKey
    {
        return $this->currentApiKey;
    }

    protected function currentOrganization(): ?Organization
    {
        return $this->currentOrganization;
    }

    /**
     * Port of `params.require(key)`: raises the ParameterMissing envelope
     * when the param is absent or empty.
     */
    protected function requireParam(Request $request, string $key): mixed
    {
        $value = $request->input($key);

        if ($value === null || $value === '') {
            throw new ParameterMissingException($key);
        }

        return $value;
    }

    /**
     * Port of ActionController::Parameters#permit — filters a nested input
     * hash against a schema:
     *  - a string filter (`'name'`) keeps only scalar-or-null values;
     *  - the '*' filter (`'properties' => '*'`) keeps an arbitrary hash
     *    verbatim (Rails' `properties: {}` — hash with unconstrained keys);
     *  - an empty array filter (`'email_settings' => []`) keeps arrays of
     *    scalars;
     *  - an array-of-arrays filter (`'metadata' => [[...]]`) keeps arrays of
     *    hashes filtered against the inner schema;
     *  - a plain array filter (`'billing_configuration' => [...]`) filters a
     *    nested hash against the sub-schema.
     * Unknown keys, non-matching shapes and nested non-permitted keys are
     * dropped, like Rails.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed|string>  $schema
     * @return array<string, mixed>
     */
    protected function permitParams(array $input, array $schema): array
    {
        $out = [];

        foreach ($schema as $schemaKey => $filter) {
            // Bare entries (`'name', ...`) are scalar filters.
            if (is_int($schemaKey)) {
                $schemaKey = $filter;
                $filter = '__scalar__';
            }

            if (! array_key_exists($schemaKey, $input)) {
                continue;
            }

            $value = $input[$schemaKey];

            if ($filter === '__scalar__') {
                if ($value === null || is_scalar($value)) {
                    $out[$schemaKey] = $value;
                }
            } elseif ($filter === '*') {
                if (is_array($value)) {
                    $out[$schemaKey] = $value;
                }
            } elseif ($filter === []) {
                if (is_array($value)) {
                    $out[$schemaKey] = array_values(array_filter($value, fn ($entry): bool => $entry === null || is_scalar($entry)));
                }
            } elseif (is_array($filter[0] ?? null)) {
                if (is_array($value)) {
                    $entries = [];

                    foreach ($value as $entry) {
                        if (is_array($entry)) {
                            $entries[] = $this->permitParams($entry, $filter[0]);
                        }
                    }

                    $out[$schemaKey] = $entries;
                }
            } elseif (is_array($value)) {
                $out[$schemaKey] = $this->permitParams($value, $filter);
            }
        }

        return $out;
    }

    /**
     * Port of ApiErrors#render_error_response — maps a service result's
     * failure onto the matching error envelope by raising the exception the
     * global handler renders. Unknown failures re-raise like Rails.
     */
    protected function renderErrorResponse(\App\Services\BaseResult $result): never
    {
        $error = $result->getError();

        if ($error === null) {
            throw new LogicException('renderErrorResponse called with a successful result');
        }

        if ($error instanceof \App\Services\Failures\NotFoundFailure) {
            throw new NotFoundException($error->resource);
        }

        if ($error instanceof \App\Services\Failures\MethodNotAllowedFailure) {
            throw new MethodNotAllowedException($error->code);
        }

        if ($error instanceof \App\Services\Failures\ValidationFailure) {
            throw new ValidationException($error->messages);
        }

        if ($error instanceof \App\Services\Failures\ForbiddenFailure) {
            throw new ForbiddenException($error->code);
        }

        if ($error instanceof \App\Services\Failures\UnauthorizedFailure) {
            throw new UnauthorizedException($error->getMessage());
        }

        throw $error;
    }

    /**
     * Renders a serializer's pre-encoded JSON (`$serializer->toJson()`) with
     * the JSON content type — the port of Rails' `render json: serializer`.
     */
    protected function renderSerializerJson(string $json): JsonResponse
    {
        return JsonResponse::fromJsonString($json);
    }
}
