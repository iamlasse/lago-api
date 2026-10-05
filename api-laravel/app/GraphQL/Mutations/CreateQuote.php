<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Customer;
use App\Models\Subscription;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Quotes\CreateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Quotes::Create (app/graphql/mutations/quotes/
 * create.rb): "Create a new quote" — the customer is looked up in the
 * current organization, the subscription (when given) in the customer's own,
 * everything else goes to Quotes::CreateService verbatim.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("quotes:create") lands with the
 * shared permission gate.
 */
class CreateQuote
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        // NOTE: only the top-level input keys convert (graphql-ruby's
        // keyword conversion) — billing_items is a JSON scalar, its inner
        // payload stays in the camelCase of the frozen billing-items shape.
        $input = array_combine(
            array_map(fn (string $key): string => \Illuminate\Support\Str::snake($key), array_keys(Args::input($args))),
            array_values(Args::input($args)),
        );

        $customerId = $input['customer_id'] ?? null;

        $customer = $customerId === null
            ? null
            : Customer::query()
                ->where('organization_id', $organization->id)
                ->find($customerId);

        $subscription = null;

        if ($customer !== null && ! empty($input['subscription_id'])) {
            $subscription = Subscription::query()
                ->where('customer_id', $customer->id)
                ->find($input['subscription_id']);
        }

        $params = array_diff_key($input, ['customer_id' => true, 'subscription_id' => true]);

        $result = CreateService::call(
            organization: $organization,
            customer: $customer,
            subscription: $subscription,
            params: $params,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->quote;
    }
}
