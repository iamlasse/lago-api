<?php

declare(strict_types=1);

require_once __DIR__.'/../GraphQL/GraphQLHelpers.php';

/**
 * Runs the full dev-seeder suite against the test DB and asserts the seed
 * surface (the upstream dataset the frontend logs in with): the Hooli org
 * and api key, the seeded gavin@hooli.com login, and the demo entities
 * downstream seeders hang off.
 */
const SEED_LOGIN_MUTATION = <<<'GQL'
mutation($input: LoginUserInput!) {
    loginUser(input: $input) {
        token
        user {
            id
            email
        }
    }
}
GQL;

it('seeds the full dev dataset and the seeded login works', function (): void {
    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    // 01_base: the fixed Hooli org + forced api key value + memberships.
    $organization = App\Models\Organization::query()
        ->whereKey('11111111-2222-3333-4444-555555555555')->first();

    expect($organization)->not->toBeNull()
        ->and($organization->name)->toBe('Hooli')
        ->and($organization->apiKeys()->where('value', 'lago_key-hooli-1234567890')->exists())->toBeTrue();

    $gavin = App\Models\User::query()->where('email', 'gavin@hooli.com')->first();

    expect($gavin)->not->toBeNull()
        ->and($gavin->memberships()->where('status', 0)->exists())->toBeTrue();

    // 02/20: the demo subscription the entitlements/alerts hang off.
    $subscription = App\Models\Subscription::query()
        ->where('external_id', 'sub_john-doe-main')->first();

    expect($subscription)->not->toBeNull()
        ->and((int) $subscription->status)->toBe(1); // active

    // 02: wallets, incl. the terminated one.
    expect($subscription->customer->wallets()->count())->toBe(2);

    // 06: alerts for the subscription.
    expect(
        Illuminate\Support\Facades\DB::table('usage_monitoring_alerts')
            ->where('subscription_external_id', 'sub_john-doe-main')
            ->count()
    )->toBe(3);

    // 08: plan + subscription usage thresholds.
    expect(
        Illuminate\Support\Facades\DB::table('usage_thresholds')
            ->where('subscription_id', $subscription->id)
            ->count()
    )->toBe(3);

    // 70: order forms only on approved quote versions.
    $orderForms = Illuminate\Support\Facades\DB::table('order_forms')->get();
    expect($orderForms)->not->toBeEmpty()
        ->and(
            $orderForms->every(fn ($form) => Illuminate\Support\Facades\DB::table('quote_versions')
                ->where('id', $form->quote_version_id)
                ->where('status', 'approved')
                ->exists())
        )->toBeTrue();

    // The seeded credentials log in through the GraphQL endpoint.
    $response = gqlPost(SEED_LOGIN_MUTATION, [
        'input' => ['email' => 'gavin@hooli.com', 'password' => 'ILoveLago'],
    ]);

    $response->assertOk();

    expect($response->json('data.loginUser.user.email'))->toBe('gavin@hooli.com')
        ->and($response->json('data.loginUser.token'))->not->toBeEmpty();
});
