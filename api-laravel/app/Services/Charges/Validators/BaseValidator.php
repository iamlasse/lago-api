<?php

declare(strict_types=1);

namespace App\Services\Charges\Validators;

use App\Services\BaseResult;

/**
 * Port of Rails' BaseValidator (app/services/base_validator.rb) — the tiny
 * error accumulator shared by every charge-model property validator.
 */
abstract class BaseValidator
{
    /** @var array<string, list<string>> */
    protected array $errors = [];

    public function __construct(
        protected BaseResult $result,
    ) {}

    /** @return array<string, list<string>> field => [error codes]. */
    public function messages(): array
    {
        return $this->errors;
    }

    /** @return list<string> all error codes, flattened (Rails: messages.values.flatten). */
    public function errorCodes(): array
    {
        return array_values(array_merge(...array_values($this->errors ?: [[]])));
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
