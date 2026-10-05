<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\OrderForm;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\OrderForms\VoidService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::OrderForms::Void (app/graphql/mutations/
 * order_forms/void.rb): "Void an order form" — cascades a void onto the
 * quote version.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("order_forms:void").
 */
class VoidOrderForm
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $orderForm = OrderForm::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = VoidService::call(orderForm: $orderForm);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->order_form;
    }
}
