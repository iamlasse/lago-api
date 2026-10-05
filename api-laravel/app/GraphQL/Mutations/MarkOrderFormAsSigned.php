<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\OrderForm;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\OrderForms\MarkAsSignedService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::OrderForms::MarkAsSigned (app/graphql/mutations/
 * order_forms/mark_as_signed.rb): "Mark an order form as signed" — signing
 * creates the order through the shared Orders execution path.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("order_forms:sign").
 */
class MarkOrderFormAsSigned
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

        $result = MarkAsSignedService::call(
            orderForm: $orderForm,
            signedDocument: $input['signed_document'] ?? null,
            executionMode: $input['execution_mode'] ?? null,
            executeAt: $input['execute_at'] ?? null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->order_form;
    }
}
