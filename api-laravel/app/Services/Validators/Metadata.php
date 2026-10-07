<?php

declare(strict_types=1);

namespace App\Services\Validators;

use function sort;
use function is_array;
use function array_keys;

/**
 * Port of Rails' Validators::MetadataValidator
 * (app/services/validators/metadata_validator.rb).
 *
 * Rails' errors hash holds a single string code per field (each new
 * violation overwrites the previous one); ported verbatim.
 */
final class Metadata
{
    /** @var array<string, string> field => error code (Rails overwrites, never accumulates). */
    public array $errors = [];

    /** @param array{max_keys?: int, max_key_length?: int, max_value_length?: int} $config */
    public function __construct(
        public readonly mixed $metadata,
        public readonly array $config = [],
    ) {}

    public static function defaultConfig(): array
    {
        return [
            'max_keys' => 5,
            'max_key_length' => 20,
            'max_value_length' => 100,
        ];
    }

    public function valid(): bool
    {
        $this->validateType();

        if ($this->items() === [] && $this->errors === []) {
            return true;
        }

        $this->validateSize();

        foreach ($this->items() as $item) {
            $this->validateItem($item);
        }

        return $this->errors === [];
    }

    private function config(string $key): int
    {
        return (int) ($this->config[$key] ?? self::defaultConfig()[$key]);
    }

    private function items(): array
    {
        return is_array($this->metadata) ? $this->metadata : [];
    }

    private function validateType(): void
    {
        if (! is_array($this->metadata)) {
            $this->errors['metadata'] = 'invalid_type';
        }
    }

    private function validateSize(): void
    {
        if (count($this->items()) > $this->config('max_keys')) {
            $this->errors['metadata'] = 'too_many_keys';
        }
    }

    private function validateItem(mixed $item): void
    {
        if (is_array($item) && ! $this->isAssociative($item)) {
            $this->errors['metadata'] = 'invalid_key_value_pair';

            return;
        }

        if ($item === null || is_string($item) || ! is_array($item)) {
            $this->errors['metadata'] = 'invalid_key_value_pair';

            return;
        }

        $item = $this->symbolizeKeys($item);

        $keys = array_keys($item);
        sort($keys);

        $hasContent = ($item['key'] ?? null) !== null && ($item['key'] ?? '') !== ''
            && ($item['value'] ?? null) !== null && ($item['value'] ?? '') !== '';

        if ($keys !== ['key', 'value'] || ! $hasContent) {
            $this->errors['metadata'] = 'invalid_key_value_pair';

            return;
        }

        $this->validateKeyLength($item['key']);
        $this->validateValueLength($item['value']);
        $this->validateStructure($item['value']);
    }

    /** Ruby Hash#keys.sort == [:key, :value] — exactly the two keys. */
    private function isAssociative(array $item): bool
    {
        return array_all(array_keys($item), fn($key) => is_string($key));
    }

    /** @return array<string, mixed> */
    private function symbolizeKeys(array $item): array
    {
        $symbolized = [];

        foreach ($item as $key => $value) {
            $symbolized[is_string($key) ? $key : (string) $key] = $value;
        }

        return $symbolized;
    }

    private function validateKeyLength(mixed $key): void
    {
        if (is_string($key) && mb_strlen($key) > $this->config('max_key_length')) {
            $this->errors['metadata'] = 'key_too_long';
        }
    }

    private function validateValueLength(mixed $value): void
    {
        if (is_string($value) && mb_strlen($value) > $this->config('max_value_length')) {
            $this->errors['metadata'] = 'value_too_long';
        }
    }

    private function validateStructure(mixed $value): void
    {
        if (is_array($value)) {
            $this->errors['metadata'] = 'nested_structure_not_allowed';
        }
    }
}
