<?php

namespace App\Serializers\Base;

/**
 * Port of Rails' CollectionSerializer (app/serializers/collection_serializer.rb):
 * wraps a collection under its collection name, optionally with `meta`
 * (pagination metadata goes under `meta`, never headers).
 */
class CollectionSerializer
{
    public function __construct(
        /** @var iterable<object> */
        protected readonly iterable $collection,
        /** @var class-string<\App\Serializers\Base\ModelSerializer> */
        protected readonly string $modelSerializer,
        protected readonly array $options = [],
    ) {}

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        $hash = [$this->collectionName() => $this->serializeModels()];

        if (($meta = $this->meta()) !== null && $meta !== []) {
            $hash['meta'] = $meta;
        }

        return $hash;
    }

    public function toJson(): string
    {
        return json_encode($this->serialize(), JSON_UNESCAPED_SLASHES);
    }

    public function collectionName(): string
    {
        return $this->options['collection_name'] ?? 'data';
    }

    public function meta(): mixed
    {
        return $this->options['meta'] ?? null;
    }

    /** @return list<array<string, mixed>> */
    protected function serializeModels(): array
    {
        $out = [];

        foreach ($this->collection as $model) {
            $out[] = (new $this->modelSerializer($model, $this->options))->serialize();
        }

        return $out;
    }
}
