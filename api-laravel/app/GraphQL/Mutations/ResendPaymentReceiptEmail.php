<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\Models\PaymentReceipt;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Emails\ResendService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::PaymentReceipts::ResendEmail
 * (app/graphql/mutations/payment_receipts/resend_email.rb): "Resends a
 * PaymentReceipt email" — Emails::ResendService with the to/cc/bcc
 * overrides; the payload is the receipt itself.
 */
class ResendPaymentReceiptEmail
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $paymentReceipt = PaymentReceipt::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = ResendService::call(
            resource: $paymentReceipt,
            to: $input['to'] ?? null,
            cc: $input['cc'] ?? null,
            bcc: $input['bcc'] ?? null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $paymentReceipt;
    }
}
