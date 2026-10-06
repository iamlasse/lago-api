<?php

declare(strict_types=1);

namespace App\GraphQL\Support;

use App\GraphQL\Execution\Errors;
use App\Models\Organization;
use App\Models\BillingEntity;
use App\GraphQL\Exceptions\ExecutionError;

/**
 * Port of Rails' BillingEntityArgsResolvable concern
 * (app/graphql/concerns/billing_entity_args_resolvable.rb) — replaces the
 * `billing_entity_code` argument with the matching `billing_entity_id` so
 * the analytics models only deal with ids.
 */
final class BillingEntityArgs
{
    /**
     * Mutates the (snake_cased) args in place and returns the error to
     * raise, or null when the arguments are valid — mirroring Rails'
     * `resolve_billing_entity!(args)`.
     *
     * @param  array<string, mixed>  $args
     */
    public static function resolve(Organization $organization, array &$args): ?ExecutionError
    {
        $code = $args['billing_entity_code'] ?? null;
        $id = $args['billing_entity_id'] ?? null;

        if (($code ?? '') !== '' && ($id ?? '') !== '') {
            return Errors::validationError([
                'billing_entity_id' => ["can't be present when billing_entity_code is provided"],
            ]);
        }

        if ($code === null || $code === '') {
            return null;
        }

        $billingEntity = BillingEntity::query()
            ->where('organization_id', $organization->id)
            ->where('code', $code)
            ->first();

        if ($billingEntity === null) {
            return Errors::notFoundError('billing_entity');
        }

        unset($args['billing_entity_code']);
        $args['billing_entity_id'] = $billingEntity->id;

        return null;
    }
}
