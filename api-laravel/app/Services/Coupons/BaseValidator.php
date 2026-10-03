<?php

declare(strict_types=1);

namespace App\Services\Coupons;

use App\Services\BaseResult;

/**
 * Port of Rails' BaseValidator (app/services/base_validator.rb) — the tiny
 * error accumulator shared by the coupon validators. Unlike the charges and
 * credit-notes copies, Rails' BaseValidator also receives the service args
 * (`initialize(result, **args)`), so this one takes them too.
 */
abstract class BaseValidator
{
    /** @var array<string, list<string>> */
    protected array $errors = [];

    /**
     * @param  array<string, mixed>  $args
     */
    public function __construct(
        protected BaseResult $result,
        protected array $args = [],
    ) {}

    /** @return array<string, list<string>> field => [error codes]. */
    public function messages(): array
    {
        return $this->errors;
    }

    protected function arg(string $key): mixed
    {
        return $this->args[$key] ?? null;
    }

    protected function addError(string $field, string $errorCode): false
    {
        $this->errors[$field][] = $errorCode;

        return false;
    }

    protected function errors(): bool
    {
        return $this->errors !== [];
    }
}
