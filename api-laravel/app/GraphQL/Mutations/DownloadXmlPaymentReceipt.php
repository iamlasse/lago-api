<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\Models\PaymentReceipt;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\PaymentReceipts\GenerateXmlService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::PaymentReceipts::DownloadXml
 * (app/graphql/mutations/payment_receipts/download_xml.rb): "Download an
 * PaymentReceipt XML" — regenerate the XML (GenerateXmlService, default
 * context) and return the receipt (xml_url).
 */
class DownloadXmlPaymentReceipt
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

        $result = GenerateXmlService::call(paymentReceipt: $paymentReceipt);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->payment_receipt;
    }
}
