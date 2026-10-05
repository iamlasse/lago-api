<?php

declare(strict_types=1);

namespace App\Services\QuoteVersions\Validators;

use App\Services\BaseResult;
use App\Support\Utils\Datetime;

/**
 * Port of Rails' QuoteVersions::Validators::BaseStructuralValidator
 * (app/services/quote_versions/validators/base_structural_validator.rb).
 *
 * Rails validates the billing_items payload against a JSON schema with
 * JSONSchemer and turns schema errors into the flat
 * `billing_items.<pointer>` => [error_code] shape the API surfaces. The
 * port evaluates the same definitions (Schema / OneOffSchema) with a small
 * hand-rolled walker — no JSON-schema dependency — reproducing the same
 * error fields and codes, including the x-error mapping.
 */
class StructuralValidator
{
    /** @var array<string, list<string>> */
    protected array $errors = [];

    /**
     * @param  array<string, mixed>  $definition  the schema definition
     * @param  mixed  $billingItems  the payload, as submitted (stringified keys expected)
     */
    public function __construct(
        protected BaseResult $result,
        protected mixed $billingItems,
        protected string $scope,
        protected array $definition,
    ) {}

    public function valid(): bool
    {
        $this->validateNode($this->definition, $this->billingItems, '');

        if ($this->errors !== []) {
            $this->result->validationFailure($this->errors);

            return false;
        }

        return true;
    }

    /** @return array<string, list<string>> */
    public function messages(): array
    {
        return $this->errors;
    }

    /**
     * Validates one schema node against one payload node at a JSON pointer.
     *
     * @param  array<string, mixed>  $schema
     */
    protected function validateNode(array $schema, mixed $data, string $pointer): void
    {
        $type = $schema['type'] ?? null;
        $types = is_array($type) ? $type : [$type];
        $nullable = in_array('null', $types, true);

        if ($data === null) {
            if (! $nullable) {
                $this->addSchemaError($pointer, 'type', $schema);

                return;
            }

            return;
        }

        if (! $this->typeMatches($types, $data)) {
            $this->addSchemaError($pointer, 'type', $schema);

            return;
        }

        if (is_array($data) && ! array_is_list($data)) {
            // Object node.
            $properties = $schema['properties'] ?? [];

            foreach (($schema['required'] ?? []) as $missing) {
                if (! array_key_exists($missing, $data)) {
                    $this->addError(
                        $pointer.'/'.$missing,
                        $this->codeFor($schema, 'required', 'value_is_mandatory'),
                    );
                }
            }

            foreach ($data as $key => $value) {
                $childPointer = $pointer.'/'.$key;

                if (array_key_exists($key, $properties)) {
                    $this->validateNode($properties[$key], $value, $childPointer);

                    continue;
                }

                if (array_key_exists('additionalProperties', $schema)) {
                    $this->addSchemaError($childPointer, 'additionalProperties', $schema['additionalProperties']);
                }
            }

            return;
        }

        if (is_array($data)) {
            // Array node.
            $count = count($data);

            if (($schema['minItems'] ?? null) !== null && $count < $schema['minItems']) {
                $this->addSchemaError($pointer, 'minItems', $schema);
            }

            if (($schema['maxItems'] ?? null) !== null && $count > $schema['maxItems']) {
                $this->addSchemaError($pointer, 'maxItems', $schema);
            }

            if (array_key_exists('items', $schema) && is_array($schema['items'])) {
                foreach ($data as $index => $item) {
                    $this->validateNode($schema['items'], $item, $pointer.'/'.$index);
                }
            }

            return;
        }

        // Scalar node.
        if (array_key_exists('const', $schema) && $data !== $schema['const']) {
            $this->addSchemaError($pointer, 'const', $schema);
        }

        if (($schema['enum'] ?? null) !== null && ! in_array($data, $schema['enum'], true)) {
            $this->addSchemaError($pointer, 'enum', $schema);
        }

        if (array_key_exists('minLength', $schema) && mb_strlen((string) $data) < $schema['minLength']) {
            $this->addSchemaError($pointer, 'minLength', $schema);
        }

        if (($schema['minimum'] ?? null) !== null && $data < $schema['minimum']) {
            $this->addSchemaError($pointer, 'minimum', $schema);
        }

        if (($schema['exclusiveMinimum'] ?? null) !== null && $data <= $schema['exclusiveMinimum']) {
            $this->addSchemaError($pointer, 'exclusiveMinimum', $schema);
        }

        if (($schema['format'] ?? null) !== null && ! $this->formatMatches($schema['format'], $data)) {
            $this->addSchemaError($pointer, 'format', $schema);
        }
    }

    /**
     * JSON Schema type check (null handled before this).
     *
     * @param  list<string>  $types
     */
    protected function typeMatches(array $types, mixed $data): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                // NOTE: a PHP empty array is indistinguishable from {}: it
                // satisfies both shapes, matching JSONSchemer on {} / [].
                'object' => is_array($data) && (! array_is_list($data) || $data === []),
                'array' => is_array($data) && (array_is_list($data) || $data === []),
                'string' => is_string($data),
                'integer' => is_int($data),
                'number' => is_int($data) || is_float($data),
                'boolean' => is_bool($data),
                'null' => $data === null,
                default => true,
            };

            if ($ok) {
                return true;
            }
        }

        return false;
    }

    protected function formatMatches(string $format, mixed $data): bool
    {
        if (! is_string($data)) {
            return false;
        }

        return match ($format) {
            'uuid' => preg_match('/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/', $data) === 1,
            'date-time' => Datetime::parseIso8601($data) !== null,
            default => true,
        };
    }

    /**
     * Rails: #add_schema_error — required errors fan out per missing key,
     * everything else lands on the failing pointer.
     *
     * @param  array<string, mixed>  $schema
     */
    protected function addSchemaError(string $pointer, string $keyword, array $schema): void
    {
        $this->addError(
            $pointer,
            $this->codeFor($schema, $keyword, match ($keyword) {
                'required' => 'value_is_mandatory',
                'minItems', 'maxItems' => 'invalid_count',
                'format' => 'invalid_format',
                'additionalProperties' => 'unsupported_key',
                default => 'is_invalid',
            }),
        );
    }

    /**
     * Rails: #code_for — the x-error member maps the failing keyword to the
     * code the API surfaces; a bare string applies to any keyword, anything
     * unmapped falls back to is_invalid.
     *
     * @param  array<string, mixed>  $schema
     */
    protected function codeFor(array $schema, string $keyword, string $default): string
    {
        $xError = $schema['x-error'] ?? null;

        if ($xError === null) {
            return $default;
        }

        if (is_string($xError)) {
            return $xError;
        }

        return $xError[$keyword] ?? $xError['type'] ?? 'is_invalid';
    }

    /**
     * Rails: #field_for — "billing_items.<pointer>".
     */
    protected function addError(string $pointer, string $errorCode): void
    {
        $field = 'billing_items'.str_replace('/', '.', $pointer);

        $this->errors[$field][] = $errorCode;
    }
}
