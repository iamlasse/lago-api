<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\IntegrationCustomer;
use App\Models\Integrations\EntraIdIntegration;
use App\Models\Integrations\NetsuiteIntegration;
use App\Models\Integrations\OktaIntegration;
use App\Models\Integration;
use App\Models\IntegrationItem;
use App\Models\Invoice;
use App\Models\Organization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ports of Rails' spec/graphql/mutations/integrations/{okta,entra_id}/, the
 * integration_customers mutations, the integration_items fetches and the
 * integrations/integration resolvers.
 *
 * Ledger rows: gql:query:{integration,integrations,integrationSubsidiaries,
 * integrationItems}, gql:mutation:{createIntegrationCustomer,
 * updateIntegrationCustomer,destroyIntegrationCustomer,
 * setIntegrationCustomerAsDefault,fetchIntegrationAccounts,
 * fetchIntegrationItems,syncIntegrationInvoice,syncIntegrationCreditNote,
 * syncHubspotIntegrationInvoice,syncSalesforceInvoice,
 * createEntraIdIntegration,updateEntraIdIntegration,createOktaIntegration,
 * updateOktaIntegration}.
 */
function gqlIntegrationsPlatformSetup(): array
{
    $organization = gqlCreateOrganization();
    App\Models\BillingEntity::factory()->create(['organization_id' => $organization->id]);
    $user = gqlCreateUser('integrations-platform@example.com');
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

function gqlSsoOrganization(string $integration): Organization
{
    // Rails: okta/entra are premium integrations — License.premium? plus the
    // premium_integrations flag.
    config()->set('lago.license', 'premium-token');

    $organization = gqlCreateOrganization('SSO Org '.uniqid());
    $organization->premium_integrations = [$integration];
    $organization->save();

    return $organization->refresh();
}

it('creates and updates an okta integration behind the premium flag', function (): void {
    $organization = gqlSsoOrganization('okta');
    $user = gqlCreateUser('okta-admin@example.com');
    gqlCreateMembership($user, $organization);

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateOktaIntegrationInput!) {
        createOktaIntegration(input: $input) { id code name domain clientId }
    }
    GQL, ['input' => [
        'clientId' => 'client-1',
        'clientSecret' => 'secret-1',
        'domain' => 'acme.okta-emea.com',
        'organizationName' => 'acme',
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.createOktaIntegration');

    expect($payload['code'])->toBe('okta')
        ->and($payload['name'])->toBe('Okta Integration')
        ->and($payload['domain'])->toBe('acme.okta-emea.com')
        ->and($payload['clientId'])->toBe('client-1');

    $integration = OktaIntegration::query()->firstOrFail();

    expect($integration->clientSecret())->toBe('secret-1');

    // Creating the integration enables the okta authentication method.
    expect(in_array('okta', (array) $organization->refresh()->authentication_methods, true))->toBeTrue();

    // The update writes the settings accessors and ignores the masked secret.
    $response = gqlPost(<<<'GQL'
    mutation($input: UpdateOktaIntegrationInput!) {
        updateOktaIntegration(input: $input) { id domain organizationName }
    }
    GQL, ['input' => [
        'id' => $integration->id,
        'domain' => 'moved.okta-emea.com',
        'organizationName' => 'acme-moved',
        'clientSecret' => '••••••••…ret',
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.updateOktaIntegration.domain'))->toBe('moved.okta-emea.com');

    $integration->refresh();

    expect($integration->organizationName())->toBe('acme-moved')
        ->and($integration->clientSecret())->toBe('secret-1');
})->group('ledger:gql:mutation:createOktaIntegration', 'ledger:gql:mutation:updateOktaIntegration');

it('refuses an okta integration without the premium flag', function (): void {
    [$organization, $user] = gqlIntegrationsPlatformSetup();

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateOktaIntegrationInput!) {
        createOktaIntegration(input: $input) { id }
    }
    GQL, ['input' => [
        'clientId' => 'client-1',
        'clientSecret' => 'secret-1',
        'domain' => 'acme.okta-emea.com',
        'organizationName' => 'acme',
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('premium_integration_missing')
        ->and(OktaIntegration::count())->toBe(0);
})->group('ledger:gql:mutation:createOktaIntegration');

it('creates and updates an entra id integration', function (): void {
    $organization = gqlSsoOrganization('entra_id');
    $user = gqlCreateUser('entra-admin@example.com');
    gqlCreateMembership($user, $organization);

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateEntraIdIntegrationInput!) {
        createEntraIdIntegration(input: $input) { id code name domain tenantId }
    }
    GQL, ['input' => [
        'clientId' => 'client-2',
        'clientSecret' => 'secret-2',
        'domain' => 'acme.com',
        'tenantId' => 'tenant-1',
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.createEntraIdIntegration');

    expect($payload['code'])->toBe('entra_id')
        ->and($payload['tenantId'])->toBe('tenant-1');

    // The invalid tenant_id format answers the model validation.
    $response = gqlPost(<<<'GQL'
    mutation($input: CreateEntraIdIntegrationInput!) {
        createEntraIdIntegration(input: $input) { id }
    }
    GQL, ['input' => [
        'clientId' => 'client-3',
        'clientSecret' => 'secret-3',
        'domain' => 'other.com',
        'tenantId' => 'bad tenant/../',
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('unprocessable_entity');

    $integration = EntraIdIntegration::query()->firstOrFail();

    $response = gqlPost(<<<'GQL'
    mutation($input: UpdateEntraIdIntegrationInput!) {
        updateEntraIdIntegration(input: $input) { id domain }
    }
    GQL, ['input' => ['id' => $integration->id, 'domain' => 'moved.com']],
        gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.updateEntraIdIntegration.domain'))->toBe('moved.com');

    expect(in_array('entra_id', (array) $organization->refresh()->authentication_methods, true))->toBeTrue();
})->group('ledger:gql:mutation:createEntraIdIntegration', 'ledger:gql:mutation:updateEntraIdIntegration');

it('creates an integration customer connection and manages its default flag', function (): void {
    [$organization, $user] = gqlIntegrationsPlatformSetup();

    $integration = App\Models\Integrations\AnrokIntegration::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateIntegrationCustomerInput!) {
        createIntegrationCustomer(input: $input) {
            ... on AnrokCustomer { id externalCustomerId integrationId isDefault category }
        }
    }
    GQL, ['input' => [
        'customerId' => $customer->id,
        'integrationId' => $integration->id,
        'externalCustomerId' => 'ext-cust-1',
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.createIntegrationCustomer');

    expect($payload['externalCustomerId'])->toBe('ext-cust-1')
        ->and($payload['category'])->toBe('tax')
        ->and($payload['isDefault'])->toBeTrue();

    $connection = IntegrationCustomer::query()->firstOrFail();

    // setIntegrationCustomerAsDefault is a no-op when already the default.
    $response = gqlPost(<<<'GQL'
    mutation($input: SetIntegrationCustomerAsDefaultInput!) {
        setIntegrationCustomerAsDefault(input: $input) { ... on AnrokCustomer { id isDefault } }
    }
    GQL, ['input' => ['id' => $connection->id]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.setIntegrationCustomerAsDefault.isDefault'))->toBeTrue();

    // The update routes through the sync job (anrok rows never reach the
    // provider, so the routing attribute alone changes).
    Queue::fake();

    $response = gqlPost(<<<'GQL'
    mutation($input: UpdateIntegrationCustomerInput!) {
        updateIntegrationCustomer(input: $input) { ... on AnrokCustomer { id } }
    }
    GQL, ['input' => ['id' => $connection->id, 'externalCustomerId' => 'ext-cust-2']],
        gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.updateIntegrationCustomer.id'))->toBe($connection->id);

    // The destroy deletes the row and answers its id.
    $response = gqlPost(<<<'GQL'
    mutation($input: DestroyIntegrationCustomerInput!) {
        destroyIntegrationCustomer(input: $input) { id }
    }
    GQL, ['input' => ['id' => $connection->id]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.destroyIntegrationCustomer.id'))->toBe($connection->id)
        ->and(IntegrationCustomer::count())->toBe(0);
})->group('ledger:gql:mutation:createIntegrationCustomer', 'ledger:gql:mutation:setIntegrationCustomerAsDefault', 'ledger:gql:mutation:updateIntegrationCustomer', 'ledger:gql:mutation:destroyIntegrationCustomer');

it('answers the integration queries', function (): void {
    [$organization, $user] = gqlIntegrationsPlatformSetup();

    $anrok = App\Models\Integrations\AnrokIntegration::factory()->create(['organization_id' => $organization->id]);
    $okta = OktaIntegration::factory()->create(['organization_id' => $organization->id]);

    $response = gqlPost(<<<'GQL'
    query {
        integrations(limit: 10) { collection { __typename ... on AnrokIntegration { code } ... on OktaIntegration { code } } metadata { totalCount } }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.integrations.metadata.totalCount'))->toBe(2);

    $response = gqlPost(<<<'GQL'
    query {
        integrations(types: [okta], limit: 10) { collection { __typename ... on OktaIntegration { code } } metadata { totalCount } }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.integrations.metadata.totalCount'))->toBe(1)
        ->and($response->json('data.integrations.collection.0.code'))->toBe('okta');

    // The single-integration lookup; unknown ids answer not_found.
    $response = gqlPost(<<<'GQL'
    query($id: ID) {
        integration(id: $id) { __typename ... on AnrokIntegration { id code } }
    }
    GQL, ['id' => $anrok->id], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.integration.code'))->toBe('anrok');

    $response = gqlPost(<<<'GQL'
    query($id: ID) {
        integration(id: $id) { __typename }
    }
    GQL, ['id' => '00000000-0000-0000-0000-000000000000'], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('not_found');
})->group('ledger:gql:query:integration', 'ledger:gql:query:integrations');

it('fetches integration items and lists them', function (): void {
    [$organization, $user] = gqlIntegrationsPlatformSetup();

    $integration = NetsuiteIntegration::factory()->create([
        'organization_id' => $organization->id,
        'secrets' => json_encode(['connection_id' => 'conn-1']),
    ]);

    Http::fake([
        'api.nango.dev/sync/trigger' => Http::response(['operation' => 'ok'], 200),
        'api.nango.dev/v1/netsuite/accounts*' => Http::response([
            'records' => [
                ['id' => 'acc-1', 'code' => '1000', 'name' => 'Cash'],
                ['id' => 'acc-2', 'code' => '2000', 'name' => 'AP'],
            ],
            'next_cursor' => null,
        ], 200),
    ]);

    $response = gqlPost(<<<'GQL'
    mutation($input: FetchIntegrationAccountsInput!) {
        fetchIntegrationAccounts(input: $input) { collection { externalId externalName externalAccountCode itemType } metadata { totalCount } }
    }
    GQL, ['input' => ['integrationId' => $integration->id]], gqlAuthHeaders($user, $organization->id));

    $collection = $response->json('data.fetchIntegrationAccounts.collection');

    expect($collection)->toHaveCount(2)
        ->and($collection[0]['externalId'])->toBe('acc-1')
        ->and($collection[0]['itemType'])->toBe('account')
        ->and($collection[1]['externalName'])->toBe('AP');

    // The items query reads the synced rows back.
    $response = gqlPost(<<<'GQL'
    query($integrationId: ID!, $itemType: IntegrationItemTypeEnum) {
        integrationItems(integrationId: $integrationId, itemType: $itemType, limit: 10) {
            collection { externalId externalName itemType }
            metadata { totalCount }
        }
    }
    GQL, ['integrationId' => $integration->id, 'itemType' => 'account'],
        gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.integrationItems.metadata.totalCount'))->toBe(2)
        ->and($response->json('data.integrationItems.collection.0.itemType'))->toBe('account');
})->group('ledger:gql:mutation:fetchIntegrationAccounts', 'ledger:gql:query:integrationItems');

it('fetches integration items from the provider feed', function (): void {
    [$organization, $user] = gqlIntegrationsPlatformSetup();

    $integration = NetsuiteIntegration::factory()->create([
        'organization_id' => $organization->id,
        'secrets' => json_encode(['connection_id' => 'conn-1']),
    ]);

    Http::fake([
        'api.nango.dev/sync/trigger' => Http::response(['operation' => 'ok'], 200),
        'api.nango.dev/v1/netsuite/items*' => Http::response([
            'records' => [
                ['id' => 'item-1', 'name' => 'Widgets', 'account_code' => '4000'],
            ],
            'next_cursor' => null,
        ], 200),
    ]);

    $response = gqlPost(<<<'GQL'
    mutation($input: FetchIntegrationItemsInput!) {
        fetchIntegrationItems(input: $input) { collection { externalId externalName itemType } metadata { totalCount } }
    }
    GQL, ['input' => ['integrationId' => $integration->id]], gqlAuthHeaders($user, $organization->id));

    $collection = $response->json('data.fetchIntegrationItems.collection');

    expect($collection)->toHaveCount(1)
        ->and($collection[0]['externalId'])->toBe('item-1')
        ->and($collection[0]['itemType'])->toBe('standard');
})->group('ledger:gql:mutation:fetchIntegrationItems');

it('queries the integration subsidiaries through nango', function (): void {
    [$organization, $user] = gqlIntegrationsPlatformSetup();

    $integration = NetsuiteIntegration::factory()->create([
        'organization_id' => $organization->id,
        'secrets' => json_encode(['connection_id' => 'conn-1']),
    ]);

    Http::fake([
        'api.nango.dev/v1/netsuite/subsidiaries' => Http::response([
            'records' => [['id' => 'sub-1', 'name' => 'US Inc']],
        ], 200),
    ]);

    $response = gqlPost(<<<'GQL'
    query($integrationId: ID) {
        integrationSubsidiaries(integrationId: $integrationId) {
            collection { externalId externalName }
            metadata { totalCount }
        }
    }
    GQL, ['integrationId' => $integration->id], gqlAuthHeaders($user, $organization->id));

    $collection = $response->json('data.integrationSubsidiaries.collection');

    expect($collection)->toHaveCount(1)
        ->and($collection[0]['externalId'])->toBe('sub-1');
})->group('ledger:gql:query:integrationSubsidiaries');

it('syncs invoices and credit notes to the integrations', function (): void {
    [$organization, $user] = gqlIntegrationsPlatformSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->for($customer)->create([
        'organization_id' => $organization->id,
        'status' => 1, // finalized
    ]);
    $creditNote = CreditNote::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'invoice_id' => $invoice->id,
        'status' => 1, // finalized
    ]);

    Queue::fake();

    $response = gqlPost(<<<'GQL'
    mutation($input: SyncIntegrationInvoiceInput!) {
        syncIntegrationInvoice(input: $input) { invoiceId }
    }
    GQL, ['input' => ['invoiceId' => $invoice->id]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.syncIntegrationInvoice.invoiceId'))->toBe($invoice->id);

    $response = gqlPost(<<<'GQL'
    mutation($input: SyncIntegrationCreditNoteInput!) {
        syncIntegrationCreditNote(input: $input) { creditNoteId }
    }
    GQL, ['input' => ['creditNoteId' => $creditNote->id]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.syncIntegrationCreditNote.creditNoteId'))->toBe($creditNote->id);

    Queue::assertPushed(\App\Jobs\Integrations\Aggregator\Invoices\CreateJob::class);
    Queue::assertPushed(\App\Jobs\Integrations\Aggregator\CreditNotes\CreateJob::class);

    // Unknown invoices answer the not_found envelope.
    $response = gqlPost(<<<'GQL'
    mutation($input: SyncIntegrationInvoiceInput!) {
        syncIntegrationInvoice(input: $input) { invoiceId }
    }
    GQL, ['input' => ['invoiceId' => '00000000-0000-0000-0000-000000000000']],
        gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('not_found');
})->group('ledger:gql:mutation:syncIntegrationInvoice', 'ledger:gql:mutation:syncIntegrationCreditNote');

it('syncs invoices through the hubspot and salesforce mutations', function (): void {
    [$organization, $user] = gqlIntegrationsPlatformSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->for($customer)->create([
        'organization_id' => $organization->id,
        'status' => 1,
    ]);

    Queue::fake();

    $response = gqlPost(<<<'GQL'
    mutation($input: SyncHubspotIntegrationInvoiceInput!) {
        syncHubspotIntegrationInvoice(input: $input) { invoiceId }
    }
    GQL, ['input' => ['invoiceId' => $invoice->id]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.syncHubspotIntegrationInvoice.invoiceId'))->toBe($invoice->id);

    Queue::assertPushed(\App\Jobs\Integrations\Aggregator\Invoices\CreateJob::class);

    // The salesforce variant enqueues the invoice.resynced webhook instead
    // (the webhook emission needs an endpoint on the organization).
    App\Models\WebhookEndpoint::factory()->create(['organization_id' => $organization->id]);

    $response = gqlPost(<<<'GQL'
    mutation($input: SyncSalesforceInvoiceInput!) {
        syncSalesforceInvoice(input: $input) { invoiceId }
    }
    GQL, ['input' => ['invoiceId' => $invoice->id]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.syncSalesforceInvoice.invoiceId'))->toBe($invoice->id);

    Queue::assertPushed(\App\Jobs\SendWebhookJob::class);
})->group('ledger:gql:mutation:syncHubspotIntegrationInvoice', 'ledger:gql:mutation:syncSalesforceInvoice');
