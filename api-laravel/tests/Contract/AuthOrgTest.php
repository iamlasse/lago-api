<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Support\Utils\AuthToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Contract test for the auth_org scenario, captured from the real Rails API
 * by scripts/contract/capture.sh auth_org.
 *
 * Covers the M1 auth surface end-to-end:
 *   1. REST GET /api/v1/organizations (Bearer UUID api-key auth)
 *   2. REST PUT /api/v1/organizations
 *   3. GraphQL loginUser (bcrypt password_digest, cross-language)
 *   4. GraphQL currentUser with a Rails-minted JWT
 * plus the cross-language JWT claims: the Rails-minted token from the
 * capture must authenticate here, and a Laravel-minted token must carry the
 * same claims Rails would have signed.
 */
class AuthOrgTest extends ContractCase
{
    private const string ORGANIZATION_ID = '1a4a0d6e-0000-4000-8000-000000000001';

    private const string USER_ID = '1a4a0d6e-0000-4000-8000-000000000002';

    private const string USER_EMAIL = 'capture@example.invalid';

    protected string $scenario = 'auth_org';

    protected function setUp(): void
    {
        parent::setUp();

        // Rails' capture ran with the :test ActiveJob adapter — jobs were
        // recorded, never executed. Mirror that on the replay side so
        // replayed requests do not run (e.g.) tracker jobs inline.
        Queue::fake();
    }

    public function test_replays_the_captured_requests_against_the_goldens(): void
    {
        $this->runScenario();
    }

    public function test_rails_minted_jwt_from_the_capture_authenticates_in_laravel(): void
    {
        // The manifest's request #4 carries the token Rails minted under the
        // frozen clock; Laravel must decode it with the shared SECRET_KEY_BASE.
        // The captured token is expired in wall-clock time — rewind first.
        $this->rewindTime();

        $request = $this->manifest['requests'][3];
        $header = $request['headers']['Authorization'] ?? '';

        $this->assertStringStartsWith('Bearer ', $header);

        $claims = AuthToken::decode(mb_substr($header, mb_strlen('Bearer ')));

        $this->assertNotNull($claims, 'the Rails-minted token must verify in Laravel');
        $this->assertSame(self::USER_ID, $claims['sub']);
        $this->assertSame(
            (new \Carbon\CarbonImmutable($this->manifest['captured_at'], 'UTC'))->getTimestamp() + AuthToken::THREE_HOURS,
            $claims['exp']
        );
    }

    public function test_laravel_minted_jwt_carries_the_same_claims_rails_would_sign(): void
    {
        // Vice versa: mint with Laravel's AuthToken under the frozen clock and
        // verify the claim set Rails' Utils::AuthToken would have produced.
        $token = AuthToken::encode(userId: self::USER_ID);

        $this->assertNotNull($token);

        $claims = AuthToken::decode($token);

        $this->assertSame(self::USER_ID, $claims['sub']);
        $this->assertSame(
            (new \Carbon\CarbonImmutable($this->manifest['captured_at'], 'UTC'))->getTimestamp() + AuthToken::THREE_HOURS,
            $claims['exp']
        );

        // And the Laravel-minted token must authenticate the GraphQL endpoint.
        $request = $this->manifest['requests'][3];
        $response = $this->replay([
            'method' => $request['method'],
            'path' => $request['path'],
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Content-Type' => 'application/json',
            ],
            'body' => $request['body'],
        ]);

        $response->assertOk();

        $decoded = $response->json();

        $this->assertSame(
            self::USER_EMAIL,
            $decoded['data']['currentUser']['email'] ?? null
        );
        $this->assertSame(
            self::ORGANIZATION_ID,
            $decoded['data']['currentUser']['organizations'][0]['id'] ?? null
        );
    }

    /**
     * The manifest's request #4 carries the Authorization header Rails
     * captured — a token minted at capture time under the then-current
     * SECRET_KEY_BASE and frozen clock. The replay must use the token THIS
     * run's request #3 (loginUser) minted instead.
     */
    protected function substituteRequestValues(array $request, int $oneBasedIndex, array $responses): array
    {
        if ($oneBasedIndex === 4) {
            $login = $responses[3]?->json('data.loginUser.token');

            if (is_string($login) && $login !== '') {
                $request['headers']['Authorization'] = 'Bearer '.$login;
            }
        }

        return $request;
    }

    /**
     * Resets every table before loading the fixture so repeated runs (and
     * Laravel's own rows from a previous replay) cannot collide with the
     * captured primary keys. The `migrations` bookkeeping table is kept.
     */
    protected function loadFixture(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('organizations')) {
            $this->artisan('migrate', ['--force' => true]);
        }

        $tables = DB::select(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'migrations'"
        );

        foreach ($tables as $table) {
            DB::statement('TRUNCATE TABLE public.'.data_get($table, 'tablename').' CASCADE');
        }

        parent::loadFixture();

        // The pg_dump header does `set_config('search_path', '', false)` on
        // this connection — without restoring it, every later query (the
        // replayed requests included) fails with "relation does not exist".
        DB::statement('SET search_path TO public');
    }
}
