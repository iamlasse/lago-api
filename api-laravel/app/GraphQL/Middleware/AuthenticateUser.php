<?php

declare(strict_types=1);

namespace App\GraphQL\Middleware;

use Closure;
use Throwable;
use App\Enums\MembershipStatus;
use App\Models\User;
use Illuminate\Http\Request;
use App\Support\CurrentContext;
use App\Support\Utils\AuthToken;
use Firebase\JWT\ExpiredException;
use Symfony\Component\HttpFoundation\Response;
use App\GraphQL\Support\LagoContext as LagoContextStore;

/**
 * Port of Rails' AuthenticableUser concern (app/controllers/concerns/
 * authenticable_user.rb) plus the controller-level behaviours of
 * GraphqlController (app/controllers/graphql_controller.rb):
 *
 * - resolve `Authorization: Bearer <jwt>` → current_user (users lookup by sub)
 * - `x-lago-organization` header → current_organization via the user's active
 *   memberships
 * - sliding renewal: when the token expires in less than 1 hour, respond with a
 *   fresh token in the `x-lago-token` header
 * - expired token → `{data: {}, errors: [{message, extensions: {status,
 *   code}}]}` with code `expired_jwt_token` (HTTP 200, like Rails' render)
 * - 15,000-char query cap → `query_is_too_large` (413 status extension)
 *
 * The resolved context travels on request attributes for resolvers (see
 * App\GraphQL\Support\LagoContext).
 */
class AuthenticateUser
{
    public const MAX_QUERY_LENGTH = 15_000;

    /** Rails: tokens expiring in less than 1 hour are considered near expiration. */
    public const NEAR_EXPIRATION_SECONDS = 3600;

    public function handle(Request $request, Closure $next): Response
    {
        // Rails: GraphqlController#set_context_source
        CurrentContext::$source = 'graphql';
        CurrentContext::$apiKeyId = null;

        $token = $this->token($request);

        $decoded = null;
        if ($token !== '') {
            try {
                $decoded = AuthToken::decode($token);
            } catch (ExpiredException) {
                // Rails: rescue_from JWT::ExpiredSignature → render_graphql_error
                return $this->controllerError('expired_jwt_token', 401);
            } catch (Throwable $e) {
                // Rails: re-raises in development, otherwise decoded_token is nil.
                if (app()->environment('local')) {
                    throw $e;
                }

                $decoded = null;
            }
        }

        $currentUser = null;
        if ($token !== '' && $decoded !== null && isset($decoded['sub'])) {
            $currentUser = User::query()->find($decoded['sub']);
        }

        $currentMembership = null;
        $currentOrganization = null;
        $organizationHeader = $request->headers->get('x-lago-organization');

        if ($currentUser !== null && $organizationHeader !== null && $organizationHeader !== '') {
            // Rails: current_user.memberships.active.find_by(organization_id: header)
            // (Rails enum :status, [:active, :revoked] → active == 0)
            $currentMembership = $currentUser
                ->memberships()
                ->where('status', MembershipStatus::Active)
                ->where('organization_id', $organizationHeader)
                ->first();

            $currentOrganization = $currentMembership?->organization()->first();
        }

        $loginMethod = $decoded['login_method'] ?? null;

        // Rails: permissions: (current_membership || Permission)&.permissions_hash.
        // The Permission port (config/permissions.yml driven) lands with the
        // roles slice; memberships without ported roles resolve no permissions.
        $permissions = $currentMembership !== null ? [] : null;

        LagoContextStore::set($request, $currentUser, $currentOrganization, $currentMembership, $loginMethod, $permissions);

        $renewedToken = null;
        if (
            $decoded !== null
            && isset($decoded['exp'])
            && $currentUser !== null
            && time() > ((int) $decoded['exp']) - self::NEAR_EXPIRATION_SECONDS
        ) {
            $renewedToken = AuthToken::renew($token);

            if ($renewedToken !== null && $renewedToken === '') {
                $renewedToken = null;
            }
        }

        $query = $request->isMethod('POST') ? $request->input('query') : null;

        $response = is_string($query) && mb_strlen($query) > self::MAX_QUERY_LENGTH
            // Rails: message "Max query length is 15000, your query is N"
            ? $this->controllerError(
                'query_is_too_large',
                413,
                sprintf('Max query length is %d, your query is %d', self::MAX_QUERY_LENGTH, mb_strlen($query)),
            )
            : $next($request);

        if ($renewedToken !== null) {
            // Rails: rescue → log warning only; renewal never breaks the response.
            $response->headers->set(AuthToken::LAGO_TOKEN_HEADER, $renewedToken);
        }

        return $response;
    }

    /** Rails: request.headers["Authorization"].to_s.split(" ").last */
    private function token(Request $request): string
    {
        $header = (string) $request->headers->get('Authorization', '');

        if ($header === '') {
            return '';
        }

        $parts = explode(' ', $header);

        return (string) end($parts);
    }

    /**
     * Rails' render_graphql_error: HTTP 200 with
     * `{data: {}, errors: [{message, extensions: {status, code}}]}`.
     */
    private function controllerError(string $code, int $status, ?string $message = null): Response
    {
        return response()->json([
            'data' => [],
            'errors' => [
                [
                    'message' => $message ?? $code,
                    'extensions' => [
                        'status' => $status,
                        'code' => $code,
                    ],
                ],
            ],
        ]);
    }
}
