<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';
require_once __DIR__.'/Resolvers/InvoicesResolverTest.php';

use App\Models\Invoice;
use App\Enums\InvoiceStatus;
use Illuminate\Support\Facades\Mail;

it('debug probe', function (): void {
    Mail::fake();
    [$organization, $user, $billingEntity] = gqlInvoicesSetup();
    config(['lago.license' => 'premium-license-token']);
    config(['lago.from_email' => 'sender@acme.com']);
    $billingEntity->update(['email' => 'billing@acme.com']);

    // finalizeAllInvoices path
    try {
        $page = (new \App\GraphQL\Mutations\FinalizeAllInvoices())(null, ['input' => []], app(\Nuwave\Lighthouse\Execution\HttpGraphQLContext::class));
        fwrite(STDERR, "FA-ok: ".json_encode([$page->metadata])."\n");
    } catch (\Throwable $e) {
        fwrite(STDERR, "FA-throw: ".get_class($e).": ".$e->getMessage()."\n");
    }

    // resend path
    try {
        $invoice = gqlMakeInvoice($organization, [
            'status' => InvoiceStatus::Finalized->value,
            'fees_amount_cents' => 1000,
            'billing_entity_id' => $billingEntity->id,
        ]);
        $invoice->customer->update(['email' => 'owner@acme.com']);

        $out = (new \App\GraphQL\Mutations\ResendInvoiceEmail())(null, ['input' => ['id' => $invoice->id, 'to' => ['finance@acme.com']]], app(\Nuwave\Lighthouse\Execution\HttpGraphQLContext::class));
        fwrite(STDERR, "RS-ok\n");
    } catch (\Throwable $e) {
        fwrite(STDERR, "RS-throw: ".get_class($e).": ".$e->getMessage()."\n");
    }
});
