<?php

declare(strict_types=1);

namespace App\Serializers\Base;

/**
 * Port of Rails' ModelSerializer (app/serializers/model_serializer.rb).
 *
 * Serializers are hand-written, snake_case-keyed arrays — keys must be
 * written literally exactly as Rails emits them (never auto-converted).
 * Options:
 *  - includes: list of relations to include, items may be strings or
 *    [relation => subIncludes] maps — mirrors Rails' [:customer, {plan: [:charges]}].
 */
abstract class ModelSerializer
{
    public function __construct(
        protected readonly object $model,
        protected readonly array $options = [],
    ) {}

    /** @return array<string, mixed> */
    abstract public function serialize(): array;

    public function toJson(): string
    {
        return json_encode([$this->rootName() => $this->serialize()], JSON_UNESCAPED_SLASHES);
    }

    public function rootName(): string
    {
        return $this->options['root_name'] ?? 'data';
    }

    /** Port of ModelSerializer#include?. */
    public function include(string $value): bool
    {
        foreach ($this->includes() as $include) {
            if (is_string($include) && $include === $value) {
                return true;
            }
            if (is_array($include) && array_key_exists($value, $include)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Port of ModelSerializer#included_relations — the sub-includes passed
     * down to a relation's serializer (defaults when the include is a plain
     * string).
     *
     * @return list<string|array>
     */
    public function includedRelations(string $value, array $default = []): array
    {
        foreach ($this->includes() as $include) {
            if (is_array($include) && array_key_exists($value, $include)) {
                return $include[$value] ?? [];
            }
        }

        return $default;
    }

    /** @return list<string|array> */
    protected function includes(): array
    {
        return $this->options['includes'] ?? [];
    }
}
