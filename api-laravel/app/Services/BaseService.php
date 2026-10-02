<?php

namespace App\Services;

use App\Support\CurrentContext;

/**
 * Port of Rails' BaseService (app/services/base_service.rb).
 *
 * Rails' `Model.call(**args)` -> `new(**args).call_with_middlewares`. The
 * middleware chain (LogTracer / Datadog / activity_loggable) has no
 * equivalent yet, so the entrypoints collapse to plain instantiation.
 * Subservices are invoked with the static `call()` and unwrapped with
 * `->raiseIfError()` (Rails' `raise_if_error!`), exactly like Rails.
 *
 * PHP cannot overload static and instance `call` on one class, so the
 * static entrypoint is `call()` and the instance body method — Rails'
 * `#call` — is `execute()`.
 */
abstract class BaseService
{
    /** Port of `Result = BaseResult[...]` — override in subclasses. */
    protected static function makeResult(string ...$attributes): BaseResult
    {
        return BaseResult::of(...$attributes);
    }

    /** Port of `self.call(*, **)`. */
    public static function call(mixed ...$args): BaseResult
    {
        /** @var static */
        $service = new static(...$args);

        return $service->execute();
    }

    /**
     * Port of `self.call!` — runs the service and raises on failure.
     * Returns the result's payload via the named attribute of the caller.
     */
    public static function callBang(mixed ...$args): BaseResult
    {
        return static::call(...$args)->raiseIfError();
    }

    /** Port of the instance-level `#call`. */
    abstract public function execute(): BaseResult;

    /** Port of the instance-level `#call!`. */
    public function callOrFail(): BaseResult
    {
        return $this->execute()->raiseIfError();
    }

    protected function __construct() {}

    protected function source(): ?string
    {
        return CurrentContext::$source;
    }

    protected function apiContext(): bool
    {
        return $this->source() === 'api';
    }

    protected function graphqlContext(): bool
    {
        return $this->source() === 'graphql';
    }

    /**
     * Port of `License.premium?` (lib/lago_utils/lago_utils/license.rb).
     * Rails verifies LAGO_LICENSE against LAGO_PREMIUM_URL at boot; until
     * that HTTP verification is ported we treat a present license key as
     * premium.
     *
     * TODO(port): verify the license against LAGO_PREMIUM_URL like Rails'
     * License#verify.
     */
    protected function premium(): bool
    {
        return env('LAGO_LICENSE') !== null && env('LAGO_LICENSE') !== '';
    }

    /**
     * Rescues the common failure modes of a service body the way Rails'
     * trailing rescue blocks do (FailedResult raised by a nested
     * `raiseIfError` -> embed it into this service's result).
     */
    protected function rescueFailures(callable $body, BaseResult $result): BaseResult
    {
        try {
            return $body();
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    protected function embedFailure(BaseResult $result, FailedResult $e): BaseResult
    {
        return $result->failWithError($e);
    }

    /** Rethrows the original error when a failure is raised mid-chain. */
    protected function originalError(Throwable $e): Throwable
    {
        return $e->getPrevious() ?? $e;
    }
}
