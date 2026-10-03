<?php

declare(strict_types=1);

namespace App\Services\CreditNotes;

use App\Services\BaseResult;

/**
 * Port of Rails' BaseValidator (app/services/base_validator.rb) — the tiny
 * error accumulator shared by the credit-note validators.
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
