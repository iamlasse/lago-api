<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Customer;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Wallets\CreateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Wallets::Create
 * (app/graphql/mutations/wallets/create.rb): "Creates a new Customer
 * Wallet" — the customer resolved by id from the current organization
 * (nilable; the create service answers customer_not_found), merged into the
 * wallet params with the organization id like Rails' `.merge(customer:)`
 * / `.merge(organization_id:)`.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("wallets:create") lands with
 * the roles/Permission slice (context permissions are not populated yet —
 * every ported mutation waits on it).
 */
class CreateCustomerWallet
{
    /** Rails: BaseQuery::UUID_REGEX — the uuid attribute cast's valid shape. */
    private const UUID_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // GraphQL carries metadata as [{key,value}]; the metadata store is a
        // hash (Rails stores the pairs list, the ItemMetadata port requires
        // the hash — normalized here so both wires land identically).
        if (isset($input['metadata']) && is_array($input['metadata']) && array_is_list($input['metadata'])) {
            $hash = [];

            foreach ($input['metadata'] as $pair) {
                if (is_array($pair) && isset($pair['key']) && is_scalar($pair['key'])) {
                    $hash[(string) $pair['key']] = isset($pair['value']) && is_scalar($pair['value'])
                        ? (string) $pair['value']
                        : null;
                }
            }

            $input['metadata'] = $hash;
        }

        // Rails: current_organization.customers.find_by(id: args[:customer_id])
        // — a non-uuid id finds nothing.
        $customerId = $input['customer_id'] ?? null;

        $customer = is_string($customerId) && preg_match(self::UUID_REGEX, $customerId) === 1
            ? Customer::query()->where('organization_id', $organization->id)->find($customerId)
            : null;

        $result = CreateService::call(params: array_merge($input, [
            'organization_id' => $organization->id,
            'customer' => $customer,
        ]));

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->wallet;
    }
}
