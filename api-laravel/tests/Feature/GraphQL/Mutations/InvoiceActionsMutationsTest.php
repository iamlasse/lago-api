<?php

declare(strict_types=1);

require_once __DIR__.'/../GraphQLHelpers.php';
require_once __DIR__.'/../AuthPlumbingTest.php';
require_once __DIR__.'/../Resolvers/InvoicesResolverTest.php';

use App\Models\AddOn;
use App\Models\Invoice;
use App\Models\Customer;
use App\Enums\InvoiceStatus;
use App\Enums\InvoicePaymentStatus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * Ports of Rails' spec/graphql/mutations/invoices/{create,update,
 * finalize_all,retry_all,retry_payment,retry_all_payments,lose_dispute,
 * download_xml,resend_email}_spec.rb, the customers/update_invoice_grace_period
 * spec and the integrations/fetch_draft_invoice_taxes spec over the frozen
 * SDL.
 *
 * Ledger rows: gql:mutation:createInvoice, gql:mutation:updateInvoice,
 * gql:mutation:finalizeAllInvoices, gql:mutation:retryAllInvoices,
 * gql:mutation:retryInvoicePayment, gql:mutation:retryAllInvoicePayments,
 * gql:mutation:loseInvoiceDispute, gql:mutation:downloadInvoiceXml,
 * gql:mutation:resendInvoiceEmail, gql:mutation:fetchDraftInvoiceTaxes,
 * gql:mutation:updateCustomerInvoiceGracePeriod.
 */
beforeEach(function (): void {
    Queue::fake();
    Mail::fake();
});

const CREATE_INVOICE_MUTATION = <<<'GQL'
mutation($input: CreateInvoiceInput!) {
    createInvoice(input: $input) { id status invoiceType currency feesAmountCents totalAmountCents purchaseOrderNumber }
}
GQL;

it('creates the one-off invoice through createInvoice', function (): void {
    [$organization, $user, $billingEntity] = gqlInvoicesSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

    $response = gqlPost(
        CREATE_INVOICE_MUTATION,
        ['input' => [
            'customerId' => $customer->id,
            'currency' => 'EUR',
            'fees' => [[
                'addOnId' => $addOn->id,
                'unitAmountCents' => 1200,
                'units' => 2.0,
                'description' => 'desc-123',
                'fromDatetime' => now()->startOfMonth()->toIso8601String(),
                'toDatetime' => now()->endOfMonth()->toIso8601String(),
            ]],
            'purchaseOrderNumber' => 'po-77',
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.createInvoice');

    expect($payload['status'])->toBe('finalized')
        ->and($payload['invoiceType'])->toBe('one_off')
        ->and($payload['currency'])->toBe('EUR')
        ->and($payload['totalAmountCents'])->toBe('2400')
        ->and($payload['purchaseOrderNumber'])->toBe('po-77')
        ->and($response->json('errors'))->toBeNull();
})->group('ledger:gql:mutation:createInvoice');

it('answers the customer not_found envelope on createInvoice', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $response = gqlPost(
        CREATE_INVOICE_MUTATION,
        ['input' => [
            'customerId' => '00000000-0000-0000-0000-000000000000',
            'currency' => 'EUR',
            'fees' => [],
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions'))->toBe([
        'status' => 404,
        'code' => 'not_found',
        'details' => ['customer' => ['not_found']],
    ]);
})->group('ledger:gql:mutation:createInvoice');

const UPDATE_INVOICE_MUTATION = <<<'GQL'
mutation($input: UpdateInvoiceInput!) {
    updateInvoice(input: $input) { id paymentStatus }
}
GQL;

it('updates the invoice payment status through updateInvoice', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $invoice = gqlMakeInvoice($organization, ['payment_status' => InvoicePaymentStatus::Pending->value]);

    $response = gqlPost(
        UPDATE_INVOICE_MUTATION,
        ['input' => ['id' => $invoice->id, 'paymentStatus' => 'succeeded']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.updateInvoice.id'))->toBe($invoice->id)
        ->and($response->json('data.updateInvoice.paymentStatus'))->toBe('succeeded')
        ->and($invoice->refresh()->paymentStatusEnum())->toBe(InvoicePaymentStatus::Succeeded);
})->group('ledger:gql:mutation:updateInvoice');

it('answers the update_on_voided_invoice not_allowed error on a voided invoice', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $invoice = gqlMakeInvoice($organization, ['status' => InvoiceStatus::Voided->value]);

    $response = gqlPost(
        UPDATE_INVOICE_MUTATION,
        ['input' => ['id' => $invoice->id, 'paymentStatus' => 'succeeded']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions.code'))->toBe('update_on_voided_invoice');
})->group('ledger:gql:mutation:updateInvoice');

const FINALIZE_ALL_MUTATION = <<<'GQL'
mutation {
    finalizeAllInvoices(input: {}) { collection { id status } metadata { totalCount } }
}
GQL;

it('enqueues the finalize-all batch and answers the draft invoices', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $draft = gqlMakeInvoice($organization, ['status' => InvoiceStatus::Draft->value]);
    gqlMakeInvoice($organization, ['status' => InvoiceStatus::Finalized->value]);

    $response = gqlPost(
        FINALIZE_ALL_MUTATION,
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.finalizeAllInvoices');

    expect($payload['metadata']['totalCount'])->toBe(1)
        ->and($payload['collection'][0]['id'])->toBe($draft->id)
        ->and($payload['collection'][0]['status'])->toBe('draft');

    Queue::assertPushed(App\Jobs\Invoices\FinalizeAllJob::class);
})->group('ledger:gql:mutation:finalizeAllInvoices');

const RETRY_ALL_MUTATION = <<<'GQL'
mutation {
    retryAllInvoices(input: {}) { collection { id status } metadata { totalCount } }
}
GQL;

it('enqueues the retry-all batch and answers the failed invoices', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $failed = gqlMakeInvoice($organization, ['status' => InvoiceStatus::Failed->value]);
    gqlMakeInvoice($organization, ['status' => InvoiceStatus::Finalized->value]);

    $response = gqlPost(
        RETRY_ALL_MUTATION,
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.retryAllInvoices');

    expect($payload['metadata']['totalCount'])->toBe(1)
        ->and($payload['collection'][0]['id'])->toBe($failed->id)
        ->and($payload['collection'][0]['status'])->toBe('failed');

    Queue::assertPushed(App\Jobs\Invoices\RetryAllJob::class);
})->group('ledger:gql:mutation:retryAllInvoices');

const RETRY_PAYMENT_MUTATION = <<<'GQL'
mutation($input: RetryInvoicePaymentInput!) {
    retryInvoicePayment(input: $input) { id paymentStatus }
}
GQL;

it('re-runs the invoice payment and announces the missing provider', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    // The payment-failure webhook only dispatches with an endpoint present.
    App\Models\WebhookEndpoint::factory()->create(['organization_id' => $organization->id]);

    $invoice = gqlMakeInvoice($organization, [
        'status' => InvoiceStatus::Finalized->value,
        'payment_status' => InvoicePaymentStatus::Failed->value,
        'ready_for_payment_processing' => true,
    ]);

    $response = gqlPost(
        RETRY_PAYMENT_MUTATION,
        ['input' => ['id' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.retryInvoicePayment.id'))->toBe($invoice->id)
        ->and($response->json('errors'))->toBeNull();

    // Without a payment provider the invoice.payment_failure webhook carries
    // the customer_must_have_payment_provider error details.
    Queue::assertPushed(App\Jobs\SendWebhookJob::class, function ($job): bool {
        return $job->webhookType === 'invoice.payment_failure';
    });
})->group('ledger:gql:mutation:retryInvoicePayment');

it('answers the invalid_status not_allowed error on a draft invoice payment retry', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $invoice = gqlMakeInvoice($organization, ['status' => InvoiceStatus::Draft->value]);

    $response = gqlPost(
        RETRY_PAYMENT_MUTATION,
        ['input' => ['id' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions.code'))->toBe('invalid_status');
})->group('ledger:gql:mutation:retryInvoicePayment');

const RETRY_ALL_PAYMENTS_MUTATION = <<<'GQL'
mutation {
    retryAllInvoicePayments(input: {}) { collection { id } metadata { totalCount } }
}
GQL;

it('enqueues the retry-all payments batch over the eligible invoices', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $eligible = gqlMakeInvoice($organization, [
        'status' => InvoiceStatus::Finalized->value,
        'payment_status' => InvoicePaymentStatus::Pending->value,
        'ready_for_payment_processing' => true,
    ]);
    gqlMakeInvoice($organization, [
        'status' => InvoiceStatus::Draft->value,
        'payment_status' => InvoicePaymentStatus::Pending->value,
        'ready_for_payment_processing' => true,
    ]);

    $response = gqlPost(
        RETRY_ALL_PAYMENTS_MUTATION,
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.retryAllInvoicePayments');

    expect($payload['metadata']['totalCount'])->toBe(1)
        ->and($payload['collection'][0]['id'])->toBe($eligible->id);

    Queue::assertPushed(App\Jobs\Invoices\Payments\RetryAllJob::class);
})->group('ledger:gql:mutation:retryAllInvoicePayments');

const LOSE_DISPUTE_MUTATION = <<<'GQL'
mutation($input: LoseInvoiceDisputeInput!) {
    loseInvoiceDispute(input: $input) { id paymentDisputeLostAt paymentDisputeLosable }
}
GQL;

it('marks the payment dispute as lost', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $invoice = gqlMakeInvoice($organization, ['status' => InvoiceStatus::Finalized->value]);

    $response = gqlPost(
        LOSE_DISPUTE_MUTATION,
        ['input' => ['id' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.loseInvoiceDispute.id'))->toBe($invoice->id)
        ->and($response->json('data.loseInvoiceDispute.paymentDisputeLostAt'))->not->toBeNull()
        ->and($invoice->refresh()->payment_dispute_lost_at)->not->toBeNull();
})->group('ledger:gql:mutation:loseInvoiceDispute');

it('answers the not_disputable not_allowed error on a draft invoice', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $invoice = gqlMakeInvoice($organization, ['status' => InvoiceStatus::Draft->value]);

    $response = gqlPost(
        LOSE_DISPUTE_MUTATION,
        ['input' => ['id' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions.code'))->toBe('not_disputable');
})->group('ledger:gql:mutation:loseInvoiceDispute');

const DOWNLOAD_XML_MUTATION = <<<'GQL'
mutation($input: DownloadXmlInvoiceInput!) {
    downloadInvoiceXml(input: $input) { id status xmlUrl }
}
GQL;

it('answers the invoice through downloadInvoiceXml', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    // The UBL renderer is a later slice: the service answers success without
    // a file, exactly like the ported invoice download_xml endpoint.
    $invoice = gqlMakeInvoice($organization, ['status' => InvoiceStatus::Finalized->value]);

    $response = gqlPost(
        DOWNLOAD_XML_MUTATION,
        ['input' => ['id' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.downloadInvoiceXml.id'))->toBe($invoice->id)
        ->and($response->json('data.downloadInvoiceXml.xmlUrl'))->toBeNull();
})->group('ledger:gql:mutation:downloadInvoiceXml');

it('answers the is_draft not_allowed error when downloading a draft XML', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $invoice = gqlMakeInvoice($organization, ['status' => InvoiceStatus::Draft->value]);

    $response = gqlPost(
        DOWNLOAD_XML_MUTATION,
        ['input' => ['id' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions.code'))->toBe('is_draft');
})->group('ledger:gql:mutation:downloadInvoiceXml');

const RESEND_INVOICE_EMAIL_MUTATION = <<<'GQL'
mutation($input: ResendInvoiceEmailInput!) {
    resendInvoiceEmail(input: $input) { id }
}
GQL;

it('resends the invoice email with the custom recipients', function (): void {
    [$organization, $user, $billingEntity] = gqlInvoicesSetup();
    config(['lago.license' => 'premium-license-token']);
    config(['lago.from_email' => 'sender@acme.com']);

    $billingEntity->update(['email' => 'billing@acme.com']);

    $invoice = gqlMakeInvoice($organization, [
        'status' => InvoiceStatus::Finalized->value,
        'fees_amount_cents' => 1000,
        'billing_entity_id' => $billingEntity->id,
    ]);
    $invoice->customer->update(['email' => 'owner@acme.com']);

    $response = gqlPost(
        RESEND_INVOICE_EMAIL_MUTATION,
        ['input' => ['id' => $invoice->id, 'to' => ['finance@acme.com']]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.resendInvoiceEmail.id'))->toBe($invoice->id)
        ->and($response->json('errors'))->toBeNull();

    config(['lago.license' => null]);
    config(['lago.from_email' => null]);
})->group('ledger:gql:mutation:resendInvoiceEmail');

it('answers the premium_license_required forbidden error without a license', function (): void {
    [$organization, $user, $billingEntity] = gqlInvoicesSetup();

    $invoice = gqlMakeInvoice($organization, ['status' => InvoiceStatus::Finalized->value]);

    $response = gqlPost(
        RESEND_INVOICE_EMAIL_MUTATION,
        ['input' => ['id' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions.code'))->toBe('premium_license_required')
        ->and($response->json('errors.0.extensions.status'))->toBe(403);
})->group('ledger:gql:mutation:resendInvoiceEmail');

it('answers the validation errors on resend without a billing entity email', function (): void {
    [$organization, $user, $billingEntity] = gqlInvoicesSetup();
    config(['lago.license' => 'premium-license-token']);
    config(['lago.from_email' => 'sender@acme.com']);

    // A billing entity without a configured email can never be emailed from.
    $entity = App\Models\BillingEntity::factory()->create([
        'organization_id' => $organization->id,
        'email' => null,
    ]);

    $invoice = gqlMakeInvoice($organization, [
        'status' => InvoiceStatus::Finalized->value,
        'fees_amount_cents' => 1000,
        'billing_entity_id' => $entity->id,
    ]);
    $invoice->customer->update(['email' => 'owner@acme.com']);

    $response = gqlPost(
        RESEND_INVOICE_EMAIL_MUTATION,
        ['input' => ['id' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    $extensions = $response->json('errors.0.extensions');

    expect($extensions['status'])->toBe(422)
        ->and($extensions['details']['billingEntity'])->toBe(['must have email configured']);

    config(['lago.license' => null]);
    config(['lago.from_email' => null]);
})->group('ledger:gql:mutation:resendInvoiceEmail');

it('answers the zero-amount invoice validation error on resend', function (): void {
    [$organization, $user, $billingEntity] = gqlInvoicesSetup();
    config(['lago.license' => 'premium-license-token']);
    config(['lago.from_email' => 'sender@acme.com']);
    $billingEntity->update(['email' => 'billing@acme.com']);

    // Zero-amount invoices are intentionally never emailed (#1559).
    $invoice = gqlMakeInvoice($organization, [
        'status' => InvoiceStatus::Finalized->value,
        'fees_amount_cents' => 0,
        'billing_entity_id' => $billingEntity->id,
    ]);
    $invoice->customer->update(['email' => 'owner@acme.com']);

    $response = gqlPost(
        RESEND_INVOICE_EMAIL_MUTATION,
        ['input' => ['id' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    $extensions = $response->json('errors.0.extensions');

    expect($extensions['status'])->toBe(422)
        ->and($extensions['details']['invoice'])->toBe(['must have a non-zero fees amount']);

    config(['lago.license' => null]);
    config(['lago.from_email' => null]);
})->group('ledger:gql:mutation:resendInvoiceEmail');

const FETCH_DRAFT_TAXES_MUTATION = <<<'GQL'
mutation($input: FetchDraftInvoiceTaxesInput!) {
    fetchDraftInvoiceTaxes(input: $input) { collection { itemId itemCode amountCents taxAmountCents } metadata { totalCount } }
}
GQL;

it('answers the customer not_found envelope on fetchDraftInvoiceTaxes', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $response = gqlPost(
        FETCH_DRAFT_TAXES_MUTATION,
        ['input' => [
            'customerId' => '00000000-0000-0000-0000-000000000000',
            'fees' => [],
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions'))->toBe([
        'status' => 404,
        'code' => 'not_found',
        'details' => ['customer' => ['not_found']],
    ]);
})->group('ledger:gql:mutation:fetchDraftInvoiceTaxes');

it('answers an empty collection for a customer without a tax integration', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $response = gqlPost(
        FETCH_DRAFT_TAXES_MUTATION,
        ['input' => [
            'customerId' => $customer->id,
            'currency' => 'EUR',
            'fees' => [[
                'unitAmountCents' => 1200,
                'units' => 2.0,
                'fromDatetime' => now()->toIso8601String(),
                'toDatetime' => now()->toIso8601String(),
            ]],
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.fetchDraftInvoiceTaxes');

    expect($payload['collection'])->toBe([])
        ->and($payload['metadata']['totalCount'])->toBe(0);
})->group('ledger:gql:mutation:fetchDraftInvoiceTaxes');

const UPDATE_GRACE_PERIOD_MUTATION = <<<'GQL'
mutation($input: UpdateCustomerInvoiceGracePeriodInput!) {
    updateCustomerInvoiceGracePeriod(input: $input) { id invoiceGracePeriod }
}
GQL;

it('assigns the invoice grace period to the customer', function (): void {
    [$organization, $user] = gqlInvoicesSetup();
    config(['lago.license' => 'premium-license-token']);

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $response = gqlPost(
        UPDATE_GRACE_PERIOD_MUTATION,
        ['input' => ['id' => $customer->id, 'invoiceGracePeriod' => 15]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.updateCustomerInvoiceGracePeriod.id'))->toBe($customer->id)
        ->and($response->json('data.updateCustomerInvoiceGracePeriod.invoiceGracePeriod'))->toBe(15)
        ->and($customer->refresh()->invoice_grace_period)->toBe(15);

    config(['lago.license' => null]);
})->group('ledger:gql:mutation:updateCustomerInvoiceGracePeriod');

it('answers the customer not_found envelope on updateCustomerInvoiceGracePeriod', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $response = gqlPost(
        UPDATE_GRACE_PERIOD_MUTATION,
        ['input' => ['id' => '00000000-0000-0000-0000-000000000000', 'invoiceGracePeriod' => 5]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions'))->toBe([
        'status' => 404,
        'code' => 'not_found',
        'details' => ['customer' => ['not_found']],
    ]);
})->group('ledger:gql:mutation:updateCustomerInvoiceGracePeriod');
