<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\Models\PaymentReceipt;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\PaymentReceipts\GeneratePdfService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::PaymentReceipts::Download
 * (app/graphql/mutations/payment_receipts/download.rb): "Download an
 * PaymentReceipt PDF" — regenerate the PDF (GeneratePdfService, default
 * context) and return the receipt (file_url).
 */
class DownloadPaymentReceipt
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

        $result = GeneratePdfService::call(paymentReceipt: $paymentReceipt);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->payment_receipt;
    }
}
