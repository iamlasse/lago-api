<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:POST:/api/v1/wallets',
    'ledger:rest:GET:/api/v1/wallets',
    'ledger:rest:GET:/api/v1/wallets/:id',
    'ledger:rest:PUT:/api/v1/wallets/:id',
    'ledger:rest:DELETE:/api/v1/wallets/:id',
);

use App\Models\Wallet;
use App\Models\Customer;
use Illuminate\Support\Str;
use App\Models\Organization;
use Illuminate\Support\Facades\Queue;

/**
 * Port of Rails' spec/requests/api/v1/wallets_controller_spec.rb (plus the
 * spec/support/shared_examples/wallet_actions.rb shared examples).
 *
 * Scenarios not ported (dependencies do not exist yet):
 * - payment_method payloads (no PaymentMethod model);
 * - recurring_transaction_rules (accepted and ignored — no model, the
 *   services TODO the RecurringTransactionRules slice);
 * - invoice_custom_section attach (no InvoiceCustomSection model);
 * - connections (BillingObjectConnections slice — the services TODO it);
 * - the initial top-up job assertions (schedule_top_up is the
 *   WalletTransactions::CreateJob integration seam, TODO'd in the service).
 */
function walletOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create($attributes);

    return [$organization, $organization->apiKeys()->first()];
}

function makeWallet(Customer $customer, array $attributes = []): Wallet
{
    return Wallet::factory()->forCustomer($customer)->create($attributes);
}

beforeEach(function (): void {
    Queue::fake();
});

// -- POST /api/v1/wallets --------------------------------------------------------

it('creates a wallet', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create(['currency' => 'EUR']);
    $expirationAt = '2027-06-05T00:00:00Z';

    $this->postJson('/api/v1/wallets', ['wallet' => [
        'external_customer_id' => $customer->external_id,
        'rate_amount' => '1',
        'name' => 'Wallet1',
        'priority' => 12,
        'currency' => 'EUR',
        'paid_credits' => '10',
        'granted_credits' => '10',
        'expiration_at' => $expirationAt,
        'invoice_requires_successful_payment' => true,
        'paid_top_up_min_amount_cents' => 500,
        'paid_top_up_max_amount_cents' => 10000,
        'ignore_paid_top_up_limits_on_creation' => 'true',
        'purchase_order_number' => 'PO-123',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($customer, $expirationAt) {
            $json->where('wallet.lago_id', fn ($id) => is_string($id) && $id !== '')
                ->where('wallet.lago_customer_id', $customer->id)
                ->where('wallet.external_customer_id', $customer->external_id)
                ->where('wallet.name', 'Wallet1')
                ->where('wallet.priority', 12)
                ->where('wallet.currency', 'EUR')
                ->where('wallet.status', 'active')
                ->where('wallet.expiration_at', $expirationAt)
                ->where('wallet.invoice_requires_successful_payment', true)
                ->where('wallet.paid_top_up_min_amount_cents', 500)
                ->where('wallet.paid_top_up_max_amount_cents', 10000)
                ->where('wallet.purchase_order_number', 'PO-123')
                ->where('wallet.recurring_transaction_rules', [])
                ->etc();
        });

    expect(Wallet::query()->where('organization_id', $organization->id)->count())->toBe(1);
});

it('returns a validation error when the external customer id is empty', function (): void {
    [$organization, $apiKey] = walletOrganization();

    $this->postJson('/api/v1/wallets', ['wallet' => [
        'rate_amount' => '1',
        'currency' => 'EUR',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJsonPath('error_details.customer', ['customer_not_found']);
});

it('returns a validation error when the external customer id is unknown', function (): void {
    [$organization, $apiKey] = walletOrganization();

    $this->postJson('/api/v1/wallets', ['wallet' => [
        'external_customer_id' => 'does-not-exist',
        'rate_amount' => '1',
        'currency' => 'EUR',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJsonPath('error_details.customer', ['customer_not_found']);
});

it('rejects paid credits below the paid top up minimum', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();

    $this->postJson('/api/v1/wallets', ['wallet' => [
        'external_customer_id' => $customer->external_id,
        'rate_amount' => '1',
        'paid_credits' => '10',
        'paid_top_up_min_amount_cents' => 3000,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJsonPath('error_details.paid_credits', ['amount_below_minimum']);
});

it('ignores the paid top up limits when ignore_paid_top_up_limits_on_creation is set', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();

    $this->postJson('/api/v1/wallets', ['wallet' => [
        'external_customer_id' => $customer->external_id,
        'rate_amount' => '1',
        'paid_credits' => '10',
        'paid_top_up_min_amount_cents' => 3000,
        'ignore_paid_top_up_limits_on_creation' => 'true',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('wallet.external_customer_id', $customer->external_id);
});

it('creates a wallet with metadata', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();

    $this->postJson('/api/v1/wallets', ['wallet' => [
        'external_customer_id' => $customer->external_id,
        'rate_amount' => '1',
        'name' => 'Wallet1',
        'currency' => 'EUR',
        'metadata' => ['meta_key_1' => 'meta_value_1', 'meta_key_2' => 'meta_value_2'],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        // Rails renders the metadata as the flat value hash (the
        // MetadataSerializer returns model&.value).
        ->assertJsonPath('wallet.metadata', [
            'meta_key_1' => 'meta_value_1', 'meta_key_2' => 'meta_value_2',
        ]);
});

it('accepts and ignores recurring transaction rules (TODO port)', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();

    // TODO(port): RecurringTransactionRules slice — Rails persists the rule
    // and schedules the top-up; the port accepts the params and answers a
    // success with an empty rule list.
    $this->postJson('/api/v1/wallets', ['wallet' => [
        'external_customer_id' => $customer->external_id,
        'rate_amount' => '1',
        'name' => 'Wallet1',
        'currency' => 'EUR',
        'paid_credits' => '10',
        'granted_credits' => '10',
        'recurring_transaction_rules' => [[
            'trigger' => 'interval',
            'interval' => 'monthly',
            'paid_credits' => '5',
        ]],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('wallet.recurring_transaction_rules', []);
});

it('creates a wallet with limitations', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $billableMetric = App\Models\BillableMetric::factory()->create(['organization_id' => $organization->id]);

    $this->postJson('/api/v1/wallets', ['wallet' => [
        'external_customer_id' => $customer->external_id,
        'rate_amount' => '1',
        'name' => 'Wallet1',
        'currency' => 'EUR',
        'paid_credits' => '10',
        'granted_credits' => '10',
        'applies_to' => [
            'fee_types' => ['charge'],
            'billable_metric_codes' => [$billableMetric->code],
        ],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($billableMetric) {
            // Rails merges the limitations hash at the TOP level — the
            // response carries `applies_to`, no `limitations` key.
            $json->where('wallet.applies_to.fee_types', ['charge'])
                ->where('wallet.applies_to.billable_metric_codes', [$billableMetric->code])
                ->etc();
        });
});

it('creates a wallet with the provided code', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();

    $this->postJson('/api/v1/wallets', ['wallet' => [
        'external_customer_id' => $customer->external_id,
        'rate_amount' => '1',
        'name' => 'Wallet1',
        'code' => 'custom_wallet_code',
        'currency' => 'EUR',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('wallet.code', 'custom_wallet_code');
});

it('derives the code from the name when no code is provided', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();

    $this->postJson('/api/v1/wallets', ['wallet' => [
        'external_customer_id' => $customer->external_id,
        'rate_amount' => '1',
        'name' => 'My Premium Wallet',
        'currency' => 'EUR',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('wallet.code', 'my_premium_wallet');
});

it('falls back to the default code when neither code nor name is provided', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();

    $this->postJson('/api/v1/wallets', ['wallet' => [
        'external_customer_id' => $customer->external_id,
        'rate_amount' => '1',
        'currency' => 'EUR',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('wallet.code', 'default');
});

it('rejects a code already taken by an active wallet of the customer', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    makeWallet($customer, ['code' => 'existing_code']);

    $this->postJson('/api/v1/wallets', ['wallet' => [
        'external_customer_id' => $customer->external_id,
        'rate_amount' => '1',
        'name' => 'Wallet1',
        'code' => 'existing_code',
        'currency' => 'EUR',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJsonPath('error_details.code', ['value_already_exist']);
});

it('assigns the billing entity by code', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $billingEntity = App\Models\BillingEntity::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'be_wallet',
    ]);

    $this->postJson('/api/v1/wallets', ['wallet' => [
        'external_customer_id' => $customer->external_id,
        'rate_amount' => '1',
        'name' => 'Wallet1',
        'currency' => 'EUR',
        'billing_entity_code' => $billingEntity->code,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('wallet.billing_entity_code', $billingEntity->code);
});

it('assigns the billing entity by id', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $billingEntity = App\Models\BillingEntity::factory()->create(['organization_id' => $organization->id]);

    $this->postJson('/api/v1/wallets', ['wallet' => [
        'external_customer_id' => $customer->external_id,
        'rate_amount' => '1',
        'name' => 'Wallet1',
        'currency' => 'EUR',
        'billing_entity_id' => $billingEntity->id,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('wallet.billing_entity_code', $billingEntity->code);
});

it('returns not found when the billing entity id does not match any entity', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();

    $this->postJson('/api/v1/wallets', ['wallet' => [
        'external_customer_id' => $customer->external_id,
        'rate_amount' => '1',
        'name' => 'Wallet1',
        'currency' => 'EUR',
        'billing_entity_id' => (string) Str::uuid(),
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

it('returns not found when the billing entity code does not match any entity', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();

    $this->postJson('/api/v1/wallets', ['wallet' => [
        'external_customer_id' => $customer->external_id,
        'rate_amount' => '1',
        'name' => 'Wallet1',
        'currency' => 'EUR',
        'billing_entity_code' => 'nonexistent',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- PUT /api/v1/wallets/:id -----------------------------------------------------

it('updates a wallet', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = makeWallet($customer);
    $expirationAt = '2027-06-05T00:00:00Z';

    $this->putJson('/api/v1/wallets/'.$wallet->id, ['wallet' => [
        'name' => 'wallet1',
        'expiration_at' => $expirationAt,
        'priority' => 5,
        'invoice_requires_successful_payment' => true,
        'paid_top_up_min_amount_cents' => 600,
        'paid_top_up_max_amount_cents' => 1000,
        'purchase_order_number' => 'PO-456',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($wallet, $expirationAt) {
            $json->where('wallet.lago_id', $wallet->id)
                ->where('wallet.name', 'wallet1')
                ->where('wallet.priority', 5)
                ->where('wallet.expiration_at', $expirationAt)
                ->where('wallet.invoice_requires_successful_payment', true)
                ->where('wallet.paid_top_up_min_amount_cents', 600)
                ->where('wallet.paid_top_up_max_amount_cents', 1000)
                ->where('wallet.purchase_order_number', 'PO-456')
                ->etc();
        });
});

it('returns not found when updating a wallet that does not exist', function (): void {
    [$organization, $apiKey] = walletOrganization();

    $this->putJson('/api/v1/wallets/'.Str::uuid(), ['wallet' => [
        'name' => 'wallet1',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

it('keeps the existing limitations when applies_to is omitted on update', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $billableMetric = App\Models\BillableMetric::factory()->create(['organization_id' => $organization->id]);
    $wallet = makeWallet($customer);
    App\Models\WalletTarget::query()->create([
        'wallet_id' => $wallet->id,
        'billable_metric_id' => $billableMetric->id,
        'organization_id' => $organization->id,
    ]);

    $this->putJson('/api/v1/wallets/'.$wallet->id, ['wallet' => [
        'name' => 'wallet1',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('wallet.applies_to.billable_metric_codes', [$billableMetric->code]);
});

it('updates a wallet with metadata', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = makeWallet($customer);

    $this->putJson('/api/v1/wallets/'.$wallet->id, ['wallet' => [
        'name' => 'wallet1',
        'metadata' => ['meta_key_1' => 'updated_meta_value_1', 'meta_key_3' => 'meta_value_3'],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('wallet.metadata', [
            'meta_key_1' => 'updated_meta_value_1', 'meta_key_3' => 'meta_value_3',
        ]);
});

it('updates the wallet code', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = makeWallet($customer);

    $this->putJson('/api/v1/wallets/'.$wallet->id, ['wallet' => [
        'code' => 'updated_wallet_code',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('wallet.code', 'updated_wallet_code');
});

it('rejects updating the code to a value already taken', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = makeWallet($customer);
    makeWallet($customer, ['code' => 'taken_code']);

    $this->putJson('/api/v1/wallets/'.$wallet->id, ['wallet' => [
        'code' => 'taken_code',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJsonPath('error_details.code', ['value_already_exist']);
});

it('moves the wallet to the billing entity sent on update', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $initial = App\Models\BillingEntity::factory()->create(['organization_id' => $organization->id, 'code' => 'initial_be']);
    $target = App\Models\BillingEntity::factory()->create(['organization_id' => $organization->id, 'code' => 'target_be']);
    $wallet = makeWallet($customer, ['billing_entity_id' => $initial->id]);

    $this->putJson('/api/v1/wallets/'.$wallet->id, ['wallet' => [
        'billing_entity_code' => $target->code,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('wallet.billing_entity_code', $target->code);

    expect($wallet->refresh()->billing_entity_id)->toBe($target->id);
});

it('returns not found and leaves the wallet untouched when the update billing entity is unknown', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $initial = App\Models\BillingEntity::factory()->create(['organization_id' => $organization->id, 'code' => 'initial_be']);
    $wallet = makeWallet($customer, ['billing_entity_id' => $initial->id]);

    $this->putJson('/api/v1/wallets/'.$wallet->id, ['wallet' => [
        'billing_entity_code' => 'nonexistent',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();

    expect($wallet->refresh()->billing_entity_id)->toBe($initial->id);
});

// -- GET /api/v1/wallets/:id -----------------------------------------------------

it('returns a wallet', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = makeWallet($customer);

    $this->getJson('/api/v1/wallets/'.$wallet->id, ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($wallet) {
            $json->where('wallet.lago_id', $wallet->id)
                ->where('wallet.name', $wallet->name)
                ->where('wallet.priority', 50)
                ->etc();
        });
});

it('returns not found when showing a wallet that does not exist', function (): void {
    [$organization, $apiKey] = walletOrganization();

    $this->getJson('/api/v1/wallets/'.Str::uuid(), ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- DELETE /api/v1/wallets/:id --------------------------------------------------

it('terminates a wallet', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = makeWallet($customer);

    $this->deleteJson('/api/v1/wallets/'.$wallet->id, [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($wallet) {
            $json->where('wallet.lago_id', $wallet->id)
                ->where('wallet.name', $wallet->name)
                ->where('wallet.status', 'terminated')
                ->etc();
        });

    expect($wallet->refresh()->statusEnum())->toBe(App\Enums\WalletStatus::Terminated);
});

it('returns not found when terminating a wallet that does not exist', function (): void {
    [$organization, $apiKey] = walletOrganization();

    $this->deleteJson('/api/v1/wallets/'.Str::uuid(), [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

it('returns not found when terminating a wallet of another organization', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $otherWallet = Wallet::factory()->create();

    $this->deleteJson('/api/v1/wallets/'.$otherWallet->id, [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- GET /api/v1/wallets ---------------------------------------------------------

it('returns wallets', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = makeWallet($customer);

    walletGetWithToken('/api/v1/wallets?external_customer_id='.$customer->external_id.'&page=1&per_page=1', [], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($wallet) {
            $json->where('wallets.0.lago_id', $wallet->id)
                ->where('wallets.0.name', $wallet->name)
                ->where('wallets.0.recurring_transaction_rules', [])
                ->where('wallets.0.applies_to.fee_types', [])
                ->etc();
        });
});

it('returns wallets with pagination metadata', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    makeWallet($customer);
    makeWallet($customer);

    walletGetWithToken('/api/v1/wallets?external_customer_id='.$customer->external_id.'&page=1&per_page=1', [], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) {
            $json->count('wallets', 1)
                ->where('meta.current_page', 1)
                ->where('meta.next_page', 2)
                ->where('meta.prev_page', null)
                ->where('meta.total_pages', 2)
                ->where('meta.total_count', 2)
                ->etc();
        });
});

it('filters wallets by currency', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    makeWallet($customer);
    $brlWallet = makeWallet($customer, ['balance_currency' => 'BRL', 'consumed_amount_currency' => 'BRL']);

    walletGetWithToken('/api/v1/wallets?external_customer_id='.$customer->external_id.'&currency=BRL', [], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($brlWallet) {
            $json->count('wallets', 1)
                ->where('wallets.0.lago_id', $brlWallet->id)
                ->etc();
        });
});

it('returns not found when the external customer id does not belong to the organization', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $otherCustomer = Customer::factory()->create();

    walletGetWithToken('/api/v1/wallets?external_customer_id='.$otherCustomer->external_id, [], $apiKey->value)
        ->assertNotFound();
});

it('filters wallets by billing entity codes', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $eu = App\Models\BillingEntity::factory()->create(['organization_id' => $organization->id, 'code' => 'EU']);
    $us = App\Models\BillingEntity::factory()->create(['organization_id' => $organization->id, 'code' => 'US']);
    $walletEu = makeWallet($customer, ['billing_entity_id' => $eu->id]);
    $walletUs = makeWallet($customer, ['billing_entity_id' => $us->id]);

    walletGetWithToken('/api/v1/wallets?billing_entity_codes[]=EU', [], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($walletEu) {
            $json->count('wallets', 1)
                ->where('wallets.0.lago_id', $walletEu->id)
                ->etc();
        });

    walletGetWithToken('/api/v1/wallets?billing_entity_codes[]=EU&billing_entity_codes[]=US', [], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) {
            $json->count('wallets', 2)
                ->etc();
        });

    walletGetWithToken('/api/v1/wallets?billing_entity_codes[]=EU&billing_entity_codes[]=BOGUS', [], $apiKey->value)
        ->assertNotFound();
});

// -- permissions ---------------------------------------------------------------------

it('requires an api permission to write wallets', function (): void {
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();

    Illuminate\Support\Facades\DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    Illuminate\Support\Facades\DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['wallet' => ['read']]), $apiKey->id],
    );

    $this->postJson('/api/v1/wallets', ['wallet' => [
        'external_customer_id' => $customer->external_id,
        'rate_amount' => '1',
        'currency' => 'EUR',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertForbidden()
        ->assertJsonPath('code', 'write_action_not_allowed_for_wallet');
});

it('requires an api permission to read wallets', function (): void {
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = walletOrganization();

    Illuminate\Support\Facades\DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    Illuminate\Support\Facades\DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['wallet' => ['write']]), $apiKey->id],
    );

    walletGetWithToken('/api/v1/wallets', [], $apiKey->value)
        ->assertForbidden()
        ->assertJsonPath('code', 'read_action_not_allowed_for_wallet');
});

// -- v2 mirror ------------------------------------------------------------------

it('mirrors the wallets endpoints at v2 with the beta header', function (): void {
    [$organization, $apiKey] = walletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();

    $this->postJson('/api/v2/wallets', ['wallet' => [
        'external_customer_id' => $customer->external_id,
        'rate_amount' => '1',
        'name' => 'V2 Wallet',
        'currency' => 'EUR',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('wallet.name', 'V2 Wallet');

    walletGetWithToken('/api/v2/wallets?external_customer_id='.$customer->external_id, [], $apiKey->value)
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('meta.total_count', 1);
});

/**
 * @param  array<string, mixed>  $params
 */
function walletGetWithToken(string $path, array $params = [], ?string $token = null): Illuminate\Testing\TestResponse
{
    $url = $params === [] ? $path : $path.'?'.http_build_query($params);

    return test()->getJson($url, $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
}
