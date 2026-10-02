<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Api\ParameterMissingException;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Organization;
use Illuminate\Http\Request;

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

    protected function currentApiKey(): ?ApiKey
    {
        return $this->currentApiKey;
    }

    protected function currentOrganization(): ?Organization
    {
        return $this->currentOrganization;
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
}
