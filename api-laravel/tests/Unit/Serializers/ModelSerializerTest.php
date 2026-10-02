<?php

uses()->group('ledger:ser:ModelSerializer', 'ledger:ser:CollectionSerializer');

use App\Serializers\Base\CollectionSerializer;
use App\Serializers\Base\ModelSerializer;

/**
 * Port of Rails' spec/serializers/model_serializer_spec.rb
 * (ModelSerializer#include? and #included_relations scenarios).
 */

/**
 * A minimal concrete serializer — the base class is abstract only for
 * `serialize()`; include?/includedRelations are what's under test.
 */
$serializerClass = new class extends ModelSerializer
{
    public function serialize(): array
    {
        return [];
    }
};

// A model double, matching Rails' `let(:model) { double }`.
$model = new stdClass;

it('returns false from include? when includes is blank', function () use ($serializerClass, $model) {
    $serializer = new $serializerClass($model, ['includes' => []]);

    expect($serializer->include('id'))->toBeFalse();
});

it('matches flat includes in include?', function () use ($serializerClass, $model) {
    $serializer = new $serializerClass($model, ['includes' => ['id', 'name']]);

    expect($serializer->include('id'))->toBeTrue()
        ->and($serializer->include('email'))->toBeFalse();
});

it('matches nested includes top-level only in include?', function () use ($serializerClass, $model) {
    $serializer = new $serializerClass($model, ['includes' => ['id', ['name' => ['first', 'last']]]]);

    expect($serializer->include('id'))->toBeTrue()
        ->and($serializer->include('name'))->toBeTrue()
        ->and($serializer->include('first'))->toBeFalse()
        ->and($serializer->include('foo'))->toBeFalse();
});

it('returns an empty array from includedRelations when includes is blank', function () use ($serializerClass, $model) {
    $serializer = new $serializerClass($model, ['includes' => []]);

    expect($serializer->includedRelations('id'))->toBe([]);
});

it('returns the default value from includedRelations when includes is blank', function () use ($serializerClass, $model) {
    $serializer = new $serializerClass($model, ['includes' => []]);

    expect($serializer->includedRelations('id', ['id']))->toBe(['id']);
});

it('returns an empty array from includedRelations for flat includes', function () use ($serializerClass, $model) {
    $serializer = new $serializerClass($model, ['includes' => ['id', 'name']]);

    expect($serializer->includedRelations('id'))->toBe([]);
});

it('returns the default value from includedRelations for flat includes', function () use ($serializerClass, $model) {
    $serializer = new $serializerClass($model, ['includes' => ['id', 'name']]);

    expect($serializer->includedRelations('name', ['first', 'last']))->toBe(['first', 'last']);
});

it('returns sub-includes from includedRelations for nested includes', function () use ($serializerClass, $model) {
    $serializer = new $serializerClass($model, ['includes' => ['id', ['name' => ['first', 'last']]]]);

    expect($serializer->includedRelations('name'))->toBe(['first', 'last']);
});

it('returns an empty array from includedRelations when the include is not found', function () use ($serializerClass, $model) {
    $serializer = new $serializerClass($model, ['includes' => ['id']]);

    expect($serializer->includedRelations('name'))->toBe([]);
});

/**
 * CollectionSerializer contract conventions (no dedicated Rails spec — the
 * meta-in-body convention is locked by the port plan).
 */

it('wraps collections under the collection name and puts meta in the body', function () use ($model) {
    $items = [$model, $model];
    $collection = new CollectionSerializer($items, $serializerClass::class, [
        'collection_name' => 'customers',
        'meta' => ['total_count' => 2, 'current_page' => 1],
    ]);

    expect($collection->serialize())->toBe([
        'customers' => [[], []],
        'meta' => ['total_count' => 2, 'current_page' => 1],
    ]);
});

it('omits the meta key when no meta is given', function () use ($model) {
    $collection = new CollectionSerializer([$model], $serializerClass::class, [
        'collection_name' => 'plans',
    ]);

    expect($collection->serialize())->toBe(['plans' => [[]]])
        ->and(array_keys($collection->serialize()))->not->toContain('meta');
});
