<?php

declare(strict_types=1);

namespace App\GraphQL\Providers;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Container\Container;
use Nuwave\Lighthouse\Support\Utils;
use Nuwave\Lighthouse\Schema\RootType;
use Nuwave\Lighthouse\Schema\AST\ASTHelper;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Schema\ResolverProvider as LighthouseResolverProvider;

/**
 * Replaces Lighthouse's default resolver provider so the FULL frozen SDL can
 * be served while only a fraction of its root fields are ported.
 *
 * Lighthouse's own provider throws at schema-build time for any root field
 * without a resolver class ("Could not locate a field resolver for the query
 * field …"). The frozen contract declares ~200 query root fields and ~150
 * mutations that are ported incrementally, so a hard failure is wrong at
 * this stage: instead, unimplemented root fields resolve to `null` (fields
 * whose declared type is non-null then surface the standard GraphQL null
 * violation — the agreed stub semantics until each resolver lands).
 *
 * Fields WITH a matching resolver class (convention: the field's StudlyCase
 * name inside the configured queries/mutations namespaces) resolve exactly
 * as Lighthouse would.
 *
 * The nested-field fallback mirrors graphql-ruby's behaviour on the Rails
 * side: a field like `taxIdentificationNumber` resolves the model's
 * `tax_identification_number` attribute/relation — the frozen SDL carries no
 * directives, so plain camelCase lookup (webonyx's default, exact name only)
 * would null out every snake_case column.
 */
class LagoResolverProvider extends LighthouseResolverProvider
{
    public function provideResolver(FieldValue $fieldValue): Closure
    {
        $resolverClass = $this->findResolverClass($fieldValue, '__invoke');

        if ($resolverClass !== null) {
            $resolver = Container::getInstance()->make($resolverClass);
            assert(is_object($resolver));

            return Closure::fromCallable([$resolver, '__invoke']);
        }

        if (RootType::isRootType($fieldValue->getParentName())) {
            // Not implemented yet: resolve to null (stub), mirroring the
            // incremental port documented in graphql/FULL_SCHEMA_NOTES.md.
            return static fn (): null => null;
        }

        // Rails port style: one class per GraphQL type in the types
        // namespace, with a method named after the field (App\GraphQL\Types\
        // CurrentOrganization::apiKey, Types\User::premium, …) — the direct
        // analogue of Rails' Types::* object types.
        $typeClass = $this->findTypeClassMethod($fieldValue);

        if ($typeClass !== null) {
            [$class, $method] = $typeClass;

            $instance = Container::getInstance()->make($class);
            assert(is_object($instance));

            return Closure::fromCallable([$instance, $method]);
        }

        // Return any non-null value to continue nested field resolution
        // when the root Query type is returned as part of the result.
        if (ASTHelper::getUnderlyingTypeName($fieldValue->getField()) === RootType::QUERY) {
            return static fn (): bool => true;
        }

        return static function (mixed $root, array $args, mixed $context, \GraphQL\Type\Definition\ResolveInfo $resolveInfo): mixed {
            $field = $resolveInfo->fieldName;
            $snakeField = Str::snake($field);

            if (is_array($root)) {
                if (array_key_exists($field, $root)) {
                    return $root[$field];
                }

                return array_key_exists($snakeField, $root) ? $root[$snakeField] : null;
            }

            if (is_object($root)) {
                $value = data_get($root, $field);

                if ($value === null && $snakeField !== $field) {
                    $value = data_get($root, $snakeField);
                }

                return $value;
            }

            return null;
        };
    }

    /**
     * Looks for a method named after the field on the class named after the
     * parent type inside the configured types namespaces.
     *
     * @return array{class-string, string}|null
     */
    protected function findTypeClassMethod(FieldValue $fieldValue): ?array
    {
        $parentName = Str::studly($fieldValue->getParentName());
        $method = $fieldValue->getFieldName();

        $className = Utils::namespaceClassname(
            $parentName,
            (array) config('lighthouse.namespaces.types'),
            static fn (string $class): bool => method_exists($class, $method),
        );

        return $className !== null ? [$className, $method] : null;
    }
}
