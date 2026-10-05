<?php

declare(strict_types=1);

uses()->group('ledger:gql:mutation:createQuote',
    'ledger:gql:mutation:updateQuoteVersion',
    'ledger:gql:mutation:approveQuoteVersion',
    'ledger:gql:mutation:markOrderFormAsSigned',
    'ledger:gql:mutation:voidOrderForm',
    'ledger:gql:query:quotes',
    'ledger:gql:query:orderForms',
    'ledger:svc:QuoteVersions.ComputeMentionVariablesService',
    'ledger:svc:OrderForms.CreateService',
    'ledger:svc:OrderForms.MarkAsSignedService',
    'ledger:svc:OrderForms.VoidService',
    'ledger:svc:OrderForms.ExecutionSettingsValidation');

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\Order;
use App\Models\OrderForm;

/**
 * Ports of the quotes / order forms GraphQL surface over the frozen SDL:
 * createQuote → updateQuoteVersion → approveQuoteVersion →
 * markOrderFormAsSigned, plus the quote / quotes / orderForm(s) /
 * quoteVersion queries.
 *
 * Ledger rows: gql:mutation:createQuote, gql:mutation:updateQuote,
 * gql:mutation:updateQuoteVersion, gql:mutation:approveQuoteVersion,
 * gql:mutation:voidQuoteVersion, gql:mutation:cloneQuoteVersion,
 * gql:mutation:markOrderFormAsSigned, gql:mutation:voidOrderForm,
 * gql:query:quote, gql:query:quotes, gql:query:quoteVersion,
 * gql:query:orderForm, gql:query:orderForms.
 */
function gqlQuotesSetup(): array
{
    config(['lago.license' => 'premium-license-token']);

    $organization = gqlCreateOrganization();
    $organization->feature_flags = ['order_forms'];
    $organization->save();

    $user = gqlCreateUser('quotes@example.com');
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

const CREATE_QUOTE_MUTATION = <<<'GQL'
mutation($input: CreateQuoteInput!) {
    createQuote(input: $input) {
        id
        number
        orderType
        currentVersion { id version status currency }
    }
}
GQL;

const UPDATE_QUOTE_VERSION_MUTATION = <<<'GQL'
mutation($input: UpdateQuoteVersionInput!) {
    updateQuoteVersion(input: $input) {
        id
        status
        billingItems
    }
}
GQL;

const APPROVE_QUOTE_VERSION_MUTATION = <<<'GQL'
mutation($input: ApproveQuoteVersionInput!) {
    approveQuoteVersion(input: $input) {
        id
        status
        mentionVariables
        orderForm { id status number }
    }
}
GQL;

const MARK_ORDER_FORM_AS_SIGNED_MUTATION = <<<'GQL'
mutation($input: MarkOrderFormAsSignedInput!) {
    markOrderFormAsSigned(input: $input) {
        id
        status
        order { id status executionMode }
    }
}
GQL;

const VOID_ORDER_FORM_MUTATION = <<<'GQL'
mutation($input: VoidOrderFormInput!) {
    voidOrderForm(input: $input) {
        id
        status
        voidReason
        quote { currentVersion { status voidReason } }
    }
}
GQL;

const QUOTES_QUERY = <<<'GQL'
query {
    quotes {
        collection { id number orderType currentVersion { status } }
        metadata { currentPage totalCount }
    }
}
GQL;

const ORDER_FORMS_QUERY = <<<'GQL'
query {
    orderForms {
        collection { id number status billingSnapshot }
        metadata { totalCount }
    }
}
GQL;

it('runs the full create → update → approve → sign lifecycle over GraphQL', function (): void {
    [$organization, $user] = gqlQuotesSetup();

    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $headers = gqlAuthHeaders($user, $organization->id);

    // 1. createQuote — the one_off deal.
    $created = gqlPost(CREATE_QUOTE_MUTATION, ['input' => [
        'customerId' => $customer->id,
        'orderType' => 'one_off',
    ]], $headers);

    $created->assertOk();
    $quoteId = $created->json('data.createQuote.id');
    $versionId = $created->json('data.createQuote.currentVersion.id');

    expect($created->json('data.createQuote.number'))->toMatch('/\AQT-\d{4}-\d{4,}\z/')
        ->and($created->json('data.createQuote.currentVersion.status'))->toBe('draft');

    // 2. updateQuoteVersion — the currency and the billing items snapshot.
    $addOn = App\Models\AddOn::factory()->create(['organization_id' => $organization->id]);

    $updated = gqlPost(UPDATE_QUOTE_VERSION_MUTATION, ['input' => [
        'id' => $versionId,
        'currency' => 'EUR',
        'billingItems' => [
            'addOns' => [[
                'id' => $addOn->id,
                'localId' => 'local-1',
                'type' => 'add_on',
                'payload' => [
                    'code' => $addOn->code,
                    'units' => 1,
                    'unitAmountCents' => 100,
                    'totalAmountCents' => 100,
                ],
            ]],
        ],
    ]], $headers);

    $updated->assertOk();
    expect($updated->json('data.updateQuoteVersion.status'))->toBe('draft');

    // 3. approveQuoteVersion — freezes the deal and generates the order form.
    $approved = gqlPost(APPROVE_QUOTE_VERSION_MUTATION, ['input' => ['id' => $versionId]], $headers);

    $approved->assertOk();
    expect($approved->json('data.approveQuoteVersion.status'))->toBe('approved')
        ->and($approved->json('data.approveQuoteVersion.mentionVariables.quote_currency'))->toBe('EUR')
        ->and($approved->json('data.approveQuoteVersion.orderForm.status'))->toBe('generated');

    $orderFormId = $approved->json('data.approveQuoteVersion.orderForm.id');

    // 4. markOrderFormAsSigned — flips the form and creates the order.
    $signed = gqlPost(MARK_ORDER_FORM_AS_SIGNED_MUTATION, ['input' => [
        'id' => $orderFormId,
        'executionMode' => 'order_only',
    ]], $headers);

    $signed->assertOk();
    expect($signed->json('data.markOrderFormAsSigned.status'))->toBe('signed')
        ->and($signed->json('data.markOrderFormAsSigned.order.status'))->toBe('created')
        ->and($signed->json('data.markOrderFormAsSigned.order.executionMode'))->toBe('order_only');

    // 5. The read surface agrees.
    $quotes = gqlPost(QUOTES_QUERY, [], $headers);
    $quotes->assertOk();
    expect($quotes->json('data.quotes.collection.0.id'))->toBe($quoteId)
        ->and($quotes->json('data.quotes.collection.0.currentVersion.status'))->toBe('approved');

    $orderForms = gqlPost(ORDER_FORMS_QUERY, [], $headers);
    $orderForms->assertOk();
    expect($orderForms->json('data.orderForms.collection.0.id'))->toBe($orderFormId)
        ->and($orderForms->json('data.orderForms.collection.0.status'))->toBe('signed')
        ->and($orderForms->json('data.orderForms.collection.0.billingSnapshot.addOns.0.payload.code'))->toBe($addOn->code);
});

it('voids an order form and cascades onto the quote version', function (): void {
    [$organization, $user] = gqlQuotesSetup();

    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $headers = gqlAuthHeaders($user, $organization->id);

    $created = gqlPost(CREATE_QUOTE_MUTATION, ['input' => [
        'customerId' => $customer->id,
        'orderType' => 'one_off',
    ]], $headers);

    $versionId = $created->json('data.createQuote.currentVersion.id');

    gqlPost(UPDATE_QUOTE_VERSION_MUTATION, ['input' => ['id' => $versionId, 'currency' => 'EUR']], $headers);

    $approved = gqlPost(APPROVE_QUOTE_VERSION_MUTATION, ['input' => ['id' => $versionId]], $headers);
    $orderFormId = $approved->json('data.approveQuoteVersion.orderForm.id');

    $voided = gqlPost(VOID_ORDER_FORM_MUTATION, ['input' => ['id' => $orderFormId]], $headers);

    $voided->assertOk();
    expect($voided->json('data.voidOrderForm.status'))->toBe('voided')
        ->and($voided->json('data.voidOrderForm.voidReason'))->toBe('manual')
        ->and($voided->json('data.voidOrderForm.quote.currentVersion.status'))->toBe('voided')
        ->and($voided->json('data.voidOrderForm.quote.currentVersion.voidReason'))->toBe('cascade_of_voided');
});

it('answers the not_found envelope for an unknown quote', function (): void {
    [$organization, $user] = gqlQuotesSetup();
    $headers = gqlAuthHeaders($user, $organization->id);

    $response = gqlPost(UPDATE_QUOTE_VERSION_MUTATION, ['input' => [
        'id' => Illuminate\Support\Str::uuid(),
        'currency' => 'EUR',
    ]], $headers);

    $response->assertOk();
    expect($response->json('errors.0.extensions.code'))->toBe('not_found');
});

// -- updateQuote ----------------------------------------------------------------------

const UPDATE_QUOTE_MUTATION = <<<'GQL'
mutation($input: UpdateQuoteInput!) {
    updateQuote(input: $input) {
        id
        owners { id email }
    }
}
GQL;

it('syncs the quote owners through updateQuote', function (): void {
    [$organization, $user] = gqlQuotesSetup();

    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $headers = gqlAuthHeaders($user, $organization->id);

    $quoteId = gqlPost(CREATE_QUOTE_MUTATION, ['input' => [
        'customerId' => $customer->id,
        'orderType' => 'one_off',
    ]], $headers)->assertOk()->json('data.createQuote.id');

    $payload = gqlPost(UPDATE_QUOTE_MUTATION, ['input' => [
        'id' => $quoteId,
        'owners' => [$user->id],
    ]], $headers)->assertOk()->json('data.updateQuote');

    expect($payload['id'])->toBe($quoteId)
        ->and($payload['owners'])->toBe([['id' => $user->id, 'email' => $user->email]]);

    // The sync replaces: an empty list clears the owners.
    $cleared = gqlPost(UPDATE_QUOTE_MUTATION, ['input' => [
        'id' => $quoteId,
        'owners' => [],
    ]], $headers)->assertOk()->json('data.updateQuote');

    expect($cleared['owners'])->toBe([]);
})->group('gql:mutation:updateQuote');

it('answers a validation error for an owner outside the organization', function (): void {
    [$organization, $user] = gqlQuotesSetup();

    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $headers = gqlAuthHeaders($user, $organization->id);

    $quoteId = gqlPost(CREATE_QUOTE_MUTATION, ['input' => [
        'customerId' => $customer->id,
        'orderType' => 'one_off',
    ]], $headers)->assertOk()->json('data.createQuote.id');

    $stranger = gqlCreateUser('quote-stranger@example.com');

    $response = gqlPost(UPDATE_QUOTE_MUTATION, ['input' => [
        'id' => $quoteId,
        'owners' => [$stranger->id],
    ]], $headers);

    expect($response->json('errors.0.extensions.code'))->toBe('unprocessable_entity')
        ->and($response->json('errors.0.extensions.details.owners.0'))->toBe('invalid');
});

// -- cloneQuoteVersion / voidQuoteVersion ------------------------------------------------

const CLONE_QUOTE_VERSION_MUTATION = <<<'GQL'
mutation($input: CloneQuoteVersionInput!) {
    cloneQuoteVersion(input: $input) {
        id
        version
        status
        quote { id versions { id version status } }
    }
}
GQL;

const VOID_QUOTE_VERSION_MUTATION = <<<'GQL'
mutation($input: VoidQuoteVersionInput!) {
    voidQuoteVersion(input: $input) {
        id
        status
        voidReason
        voidedAt
    }
}
GQL;

it('clones a draft version into the next draft and supersedes the previous one', function (): void {
    [$organization, $user] = gqlQuotesSetup();

    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $headers = gqlAuthHeaders($user, $organization->id);

    $created = gqlPost(CREATE_QUOTE_MUTATION, ['input' => [
        'customerId' => $customer->id,
        'orderType' => 'one_off',
    ]], $headers)->assertOk();

    $quoteId = $created->json('data.createQuote.id');
    $versionId = $created->json('data.createQuote.currentVersion.id');

    $cloned = gqlPost(CLONE_QUOTE_VERSION_MUTATION, ['input' => ['id' => $versionId]], $headers);

    $cloned->assertOk();

    $payload = $cloned->json('data.cloneQuoteVersion');

    expect($payload['id'])->not->toBe($versionId)
        ->and($payload['version'])->toBe(2)
        ->and($payload['status'])->toBe('draft')
        ->and($payload['quote']['id'])->toBe($quoteId);

    $statuses = collect($payload['quote']['versions'])->keyBy('version');

    // The superseded draft is voided (reason: superseded_by_new_draft).
    expect($statuses[1]['status'])->toBe('voided')
        ->and($statuses[2]['status'])->toBe('draft');
})->group('gql:mutation:cloneQuoteVersion');

it('answers not_clonable once a version was approved', function (): void {
    [$organization, $user] = gqlQuotesSetup();

    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $headers = gqlAuthHeaders($user, $organization->id);

    $created = gqlPost(CREATE_QUOTE_MUTATION, ['input' => [
        'customerId' => $customer->id,
        'orderType' => 'one_off',
    ]], $headers)->assertOk();

    $versionId = $created->json('data.createQuote.currentVersion.id');

    gqlPost(UPDATE_QUOTE_VERSION_MUTATION, ['input' => ['id' => $versionId, 'currency' => 'EUR']], $headers);
    gqlPost(APPROVE_QUOTE_VERSION_MUTATION, ['input' => ['id' => $versionId]], $headers);

    $response = gqlPost(CLONE_QUOTE_VERSION_MUTATION, ['input' => ['id' => $versionId]], $headers);

    expect($response->json('errors.0.extensions.code'))->toBe('unprocessable_entity')
        ->and($response->json('errors.0.extensions.details.status.0'))->toBe('not_clonable');
});

it('voids a draft version with the manual reason through voidQuoteVersion', function (): void {
    [$organization, $user] = gqlQuotesSetup();

    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $headers = gqlAuthHeaders($user, $organization->id);

    $versionId = gqlPost(CREATE_QUOTE_MUTATION, ['input' => [
        'customerId' => $customer->id,
        'orderType' => 'one_off',
    ]], $headers)->assertOk()->json('data.createQuote.currentVersion.id');

    $payload = gqlPost(VOID_QUOTE_VERSION_MUTATION, ['input' => ['id' => $versionId]], $headers)
        ->assertOk()->json('data.voidQuoteVersion');

    expect($payload['id'])->toBe($versionId)
        ->and($payload['status'])->toBe('voided')
        ->and($payload['voidReason'])->toBe('manual')
        ->and($payload['voidedAt'])->not->toBeNull();
})->group('gql:mutation:voidQuoteVersion');

it('answers not_found when voiding an unknown version', function (): void {
    [$organization, $user] = gqlQuotesSetup();

    $response = gqlPost(VOID_QUOTE_VERSION_MUTATION, ['input' => [
        'id' => Illuminate\Support\Str::uuid(),
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('not_found');
});

// -- fetches: quote / quoteVersion / order ------------------------------------------------

const QUOTE_QUERY = <<<'GQL'
query($id: ID!) {
    quote(id: $id) { id number orderType currentVersion { id status } }
}
GQL;

const QUOTE_VERSION_QUERY = <<<'GQL'
query($id: ID!) {
    quoteVersion(id: $id) { id version status currency quote { id } }
}
GQL;

const ORDER_QUERY = <<<'GQL'
query($id: ID!) {
    order(id: $id) { id number status orderType executionMode }
}
GQL;

it('fetches a single quote through quote', function (): void {
    [$organization, $user] = gqlQuotesSetup();

    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $headers = gqlAuthHeaders($user, $organization->id);

    $quoteId = gqlPost(CREATE_QUOTE_MUTATION, ['input' => [
        'customerId' => $customer->id,
        'orderType' => 'one_off',
    ]], $headers)->assertOk()->json('data.createQuote.id');

    $payload = gqlPost(QUOTE_QUERY, ['id' => $quoteId], $headers)
        ->assertOk()->json('data.quote');

    expect($payload['id'])->toBe($quoteId)
        ->and($payload['orderType'])->toBe('one_off')
        ->and($payload['currentVersion']['status'])->toBe('draft');

    // An unknown id resolves null (the field is nullable, Rails find_by).
    gqlPost(QUOTE_QUERY, ['id' => Illuminate\Support\Str::uuid()], $headers)
        ->assertOk()
        ->assertJsonPath('data.quote', null);
})->group('gql:query:quote');

it('fetches a single quote version through quoteVersion', function (): void {
    [$organization, $user] = gqlQuotesSetup();

    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $headers = gqlAuthHeaders($user, $organization->id);

    $created = gqlPost(CREATE_QUOTE_MUTATION, ['input' => [
        'customerId' => $customer->id,
        'orderType' => 'one_off',
    ]], $headers)->assertOk();

    $quoteId = $created->json('data.createQuote.id');
    $versionId = $created->json('data.createQuote.currentVersion.id');

    $payload = gqlPost(QUOTE_VERSION_QUERY, ['id' => $versionId], $headers)
        ->assertOk()->json('data.quoteVersion');

    expect($payload['id'])->toBe($versionId)
        ->and($payload['version'])->toBe(1)
        ->and($payload['status'])->toBe('draft')
        ->and($payload['quote']['id'])->toBe($quoteId);
})->group('gql:query:quoteVersion');

it('fetches a single order through order and answers not_found for an unknown id', function (): void {
    [$organization, $user] = gqlQuotesSetup();

    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $headers = gqlAuthHeaders($user, $organization->id);

    $versionId = gqlPost(CREATE_QUOTE_MUTATION, ['input' => [
        'customerId' => $customer->id,
        'orderType' => 'one_off',
    ]], $headers)->assertOk()->json('data.createQuote.currentVersion.id');

    gqlPost(UPDATE_QUOTE_VERSION_MUTATION, ['input' => ['id' => $versionId, 'currency' => 'EUR']], $headers);

    $orderFormId = gqlPost(APPROVE_QUOTE_VERSION_MUTATION, ['input' => ['id' => $versionId]], $headers)
        ->assertOk()->json('data.approveQuoteVersion.orderForm.id');

    $orderId = gqlPost(MARK_ORDER_FORM_AS_SIGNED_MUTATION, ['input' => [
        'id' => $orderFormId,
        'executionMode' => 'order_only',
    ]], $headers)->assertOk()->json('data.markOrderFormAsSigned.order.id');

    $payload = gqlPost(ORDER_QUERY, ['id' => $orderId], $headers)
        ->assertOk()->json('data.order');

    expect($payload['id'])->toBe($orderId)
        ->and($payload['status'])->toBe('created')
        ->and($payload['executionMode'])->toBe('order_only');

    gqlPost(ORDER_QUERY, ['id' => Illuminate\Support\Str::uuid()], $headers)
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'not_found');
})->group('gql:query:order');

// -- executeOrder --------------------------------------------------------------------------

const EXECUTE_ORDER_MUTATION = <<<'GQL'
mutation($input: ExecuteOrderInput!) {
    executeOrder(input: $input) {
        id
        status
        executedAt
        executionMode
        executionRecord { executionMode invoiceId errors }
    }
}
GQL;

it('executes a signed one-off order through executeOrder', function (): void {
    [$organization, $user] = gqlQuotesSetup();

    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $headers = gqlAuthHeaders($user, $organization->id);

    $versionId = gqlPost(CREATE_QUOTE_MUTATION, ['input' => [
        'customerId' => $customer->id,
        'orderType' => 'one_off',
    ]], $headers)->assertOk()->json('data.createQuote.currentVersion.id');

    gqlPost(UPDATE_QUOTE_VERSION_MUTATION, ['input' => ['id' => $versionId, 'currency' => 'EUR']], $headers);

    $orderFormId = gqlPost(APPROVE_QUOTE_VERSION_MUTATION, ['input' => ['id' => $versionId]], $headers)
        ->assertOk()->json('data.approveQuoteVersion.orderForm.id');

    $orderId = gqlPost(MARK_ORDER_FORM_AS_SIGNED_MUTATION, ['input' => [
        'id' => $orderFormId,
        'executionMode' => 'order_only',
    ]], $headers)->assertOk()->json('data.markOrderFormAsSigned.order.id');

    $payload = gqlPost(EXECUTE_ORDER_MUTATION, ['input' => ['id' => $orderId]], $headers)
        ->assertOk()->json('data.executeOrder');

    expect($payload['id'])->toBe($orderId)
        ->and($payload['status'])->toBe('executed')
        ->and($payload['executedAt'])->not->toBeNull()
        // Rails: the completed execution record.
        ->and($payload['executionRecord']['errors'])->toBe([]);
})->group('gql:mutation:executeOrder');

it('answers not_found when executing an unknown order', function (): void {
    [$organization, $user] = gqlQuotesSetup();

    $response = gqlPost(EXECUTE_ORDER_MUTATION, ['input' => [
        'id' => Illuminate\Support\Str::uuid(),
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('not_found');
});
