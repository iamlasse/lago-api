<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;
use App\Services\Failures\FailedResult;
use App\Services\Failures\ServiceFailure;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ProviderFailure;
use App\Services\Failures\ForbiddenFailure;
use App\Services\Failures\ThirdPartyFailure;
use App\Services\Failures\UnknownTaxFailure;
use App\Services\Failures\ValidationFailure;
use App\Services\Failures\NonRetryableFailure;
use App\Services\Failures\UnauthorizedFailure;
use App\Services\Failures\LockAcquisitionFailure;
use App\Services\Failures\MethodNotAllowedFailure;
use App\Services\Failures\TooManyProviderRequestsFailure;

/**
 * Port of Rails' BaseResult (app/services/base_result.rb).
 *
 * Rails declares per-service result shapes with `Result = BaseResult[:customer]`
 * (attr_accessors + equality). In PHP, services instantiate
 * `new BaseResult(['customer'])` and use magic property access for the
 * declared attributes — `result->customer = $customer`.
 */
class BaseResult
{
    protected bool $failure = false;

    protected ?FailedResult $error = null;

    /** @var array<string, mixed> */
    protected array $attributes = [];

    /** @var list<string> */
    protected array $attributeNames;

    /** @param list<string> $attributeNames port of `BaseResult[...]`. */
    final public function __construct(array $attributeNames = [])
    {
        $this->attributeNames = $attributeNames;
    }

    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    /** Port of `BaseResult[:attribute, ...]` — a fresh typed result. */
    public static function of(string ...$attributes): static
    {
        return new static($attributes);
    }

    public function success(): bool
    {
        return ! $this->failure;
    }

    public function failure(): bool
    {
        return $this->failure;
    }

    public function getError(): ?FailedResult
    {
        return $this->error;
    }

    /** Port of `raise_if_error!` — returns self, or throws the failure. */
    public function raiseIfError(): static
    {
        if ($this->success()) {
            return $this;
        }

        /** @var FailedResult $error */
        $error = $this->error;

        throw $error;
    }

    // -- Failure setters (port of the `*_failure!` methods) -----------------

    public function failWithError(FailedResult $error): static
    {
        $this->failure = true;
        $this->error = $error;

        return $this;
    }

    public function notFoundFailure(string $resource): static
    {
        return $this->failWithError(new NotFoundFailure($this, $resource));
    }

    public function notAllowedFailure(string $code): static
    {
        return $this->failWithError(new MethodNotAllowedFailure($this, $code));
    }

    /** @param array<string, list<string>> $messages */
    public function validationFailure(array $messages): static
    {
        return $this->failWithError(new ValidationFailure($this, $messages));
    }

    /**
     * Port of `record_validation_failure!(record:)` — takes the errors hash
     * of a validated model (field => [codes]).
     *
     * @param  array<string, list<string>>  $messages
     */
    public function recordValidationFailure(array $messages): static
    {
        return $this->validationFailure($messages);
    }

    public function singleValidationFailure(string $errorCode, string $field = 'base'): static
    {
        return $this->validationFailure([$field => [$errorCode]]);
    }

    public function serviceFailure(string $code, string $message, ?Throwable $error = null): static
    {
        return $this->failWithError(new ServiceFailure($this, $code, $message, $error));
    }

    public function nonRetryableFailure(string $code, string $message): static
    {
        return $this->failWithError(new NonRetryableFailure($this, $code, $message));
    }

    public function lockAcquisitionFailure(string $message, string $code = 'lock_acquisition_failed', ?Throwable $error = null): static
    {
        return $this->failWithError(new LockAcquisitionFailure($this, $code, $message, $error));
    }

    public function unknownTaxFailure(string $code, string $message): static
    {
        return $this->failWithError(new UnknownTaxFailure($this, $code, $message));
    }

    public function forbiddenFailure(string $code = 'feature_unavailable'): static
    {
        return $this->failWithError(new ForbiddenFailure($this, $code));
    }

    public function unauthorizedFailure(string $message = 'unauthorized'): static
    {
        return $this->failWithError(new UnauthorizedFailure($this, $message));
    }

    public function providerFailure(string $provider, ?Throwable $error = null): static
    {
        return $this->failWithError(new ProviderFailure($this, $provider, $error));
    }

    public function thirdPartyFailure(string $thirdParty, string $errorCode, string $errorMessage): static
    {
        return $this->failWithError(new ThirdPartyFailure($this, $thirdParty, $errorCode, $errorMessage));
    }

    public function tooManyProviderRequestsFailure(string $providerName, Throwable $error): static
    {
        return $this->failWithError(new TooManyProviderRequestsFailure($this, $providerName, $error));
    }
}
