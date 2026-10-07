<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Lago configuration — same LAGO_* environment contract as the Rails API
|--------------------------------------------------------------------------
|
| Port of the ENV reads scattered across Rails' config/application.rb,
| config/environments/*, lib/ and app/services/* so the docker compose files
| keep working unchanged. Only the names and defaults matter here.
*/

// Port of LagoUtils::Version (lib/lago_utils/lago_utils/version.rb): the
// LAGO_VERSION file at the repo root holds either a release tag or a 40-char
// git sha — in which case Rails reports the file's creation date as the
// version number (a quirk worth keeping).
$lagoVersionFile = base_path('LAGO_VERSION');
$lagoVersionContent = is_file($lagoVersionFile) ? mb_trim((string) file_get_contents($lagoVersionFile)) : null;

$lagoVersion = match (true) {
    $lagoVersionContent === null => env('APP_ENV', 'production'),
    mb_strlen($lagoVersionContent) === 40 => date('Y-m-d', (int) filectime($lagoVersionFile)),
    default => $lagoVersionContent,
};

$lagoGithubUrl = $lagoVersionContent !== null
    ? 'https://github.com/getlago/lago-api/tree/'.$lagoVersionContent
    : 'https://github.com/getlago/lago-api';

return [

    // Rails has no LAGO_* env for this: it is 1.hour in config/application.rb
    // and 10.seconds in config/environments/development.rb. The env override
    // exists so deployments (and tests) can pin it.
    'api_key_cache_ttl' => (int) env('LAGO_API_KEY_CACHE_TTL', env('APP_ENV') === 'local' ? 10 : 3600),

    // config/application.rb: config.lago_front_url
    'front_url' => env('LAGO_FRONT_URL') ?: 'https://app.getlago.com',

    // config/initializers/cors.rb: ENV["LAGO_DOMAIN"] (CORS origins)
    'domain' => env('LAGO_DOMAIN'),

    // ActionCable allowed_request_origins / outbound URL base
    'api_url' => env('LAGO_API_URL'),

    // config/environments/development.rb: config.license_url
    'license_url' => env('LAGO_LICENSE_URL', 'http://license:3000'),

    // LagoUtils::License — premium feature gate (api_permissions etc.)
    'license' => env('LAGO_LICENSE'),

    // ENV.fetch("LAGO_DISABLE_SIGNUP", "false") == "true" gate in
    // UsersService#register (app/services/users_service.rb).
    'signup_disabled' => env('LAGO_DISABLE_SIGNUP', 'false') === 'true',

    // app/services/utils/pdf_generator.rb (Gotenberg)
    'pdf_url' => env('LAGO_PDF_URL'),

    // app/mailers/invoice_mailer.rb (from_email_address fallback) and the
    // LAGO_FROM_EMAIL-anchored mailers (organization/api_key/password_reset).
    'from_email' => env('LAGO_FROM_EMAIL'),

    // Invoices::GeneratePdfService#should_generate_pdf? /
    // ApplicationMailer#set_shared_variables (@pdfs_enabled).
    'disable_pdf_generation' => env('LAGO_DISABLE_PDF_GENERATION', false),

    // DataApi::BaseService (app/services/data_api/base_service.rb) — the
    // Lago Data API the analytics proxy GETs (GET /api/v1/analytics/usage
    // and the GraphQL dataApi queries), authenticated with a bearer token.
    'data_api_url' => env('LAGO_DATA_API_URL'),
    'data_api_bearer_token' => env('LAGO_DATA_API_BEARER_TOKEN'),

    // Rails' config/database.yml `clickhouse` entry (LAGO_CLICKHOUSE_* env)
    // + the `ENV["LAGO_CLICKHOUSE_ENABLED"].present?` store switch in
    // Events::Stores::StoreFactory. Ported as the connection contract of
    // App\Services\ClickHouse\Client (HTTP on :8123, no composer adapter).
    'clickhouse' => [
        'enabled' => env('LAGO_CLICKHOUSE_ENABLED'),
        // database.yml: host LAGO_CLICKHOUSE_HOST, port 8123, database
        // LAGO_CLICKHOUSE_DATABASE, username/password, ssl (production
        // entry only — LAGO_CLICKHOUSE_SSL).
        'host' => env('LAGO_CLICKHOUSE_HOST', 'localhost'),
        'port' => (int) env('LAGO_CLICKHOUSE_PORT', 8123),
        'database' => env('LAGO_CLICKHOUSE_DATABASE', 'default'),
        'username' => env('LAGO_CLICKHOUSE_USERNAME', 'default'),
        'password' => env('LAGO_CLICKHOUSE_PASSWORD', ''),
        'ssl' => (bool) env('LAGO_CLICKHOUSE_SSL', false),
    ],

    'webhook' => [
        // app/services/webhooks/send_http_service.rb
        'timeout_seconds' => (int) env('LAGO_WEBHOOK_TIMEOUT_SECONDS', 30),
        'attempts' => (int) env('LAGO_WEBHOOK_ATTEMPTS', 3),
        // lib/lago_http_client/lago_http_client/address_guard.rb
        'allow_private_urls' => (bool) env('LAGO_WEBHOOK_ALLOW_PRIVATE_URLS', false),
        // config/initializers/rsa_keys.rb — RS256 key for JWT webhook signatures
        'rsa_private_key_path' => env('LAGO_RSA_PRIVATE_KEY_PATH', base_path('config/keys/private.pem')),
    ],

    // Auth::GoogleService (app/services/auth/google_service.rb) —
    // ENV["GOOGLE_AUTH_CLIENT_ID"] / ENV["GOOGLE_AUTH_CLIENT_SECRET"]. Okta
    // and Entra ID have NO env names in Rails: their client_id/secret/domain/
    // tenant live on the organization's `integrations` row (settings/secrets
    // columns), created through the integrations REST surface.
    'google_auth_client_id' => env('GOOGLE_AUTH_CLIENT_ID'),
    'google_auth_client_secret' => env('GOOGLE_AUTH_CLIENT_SECRET'),

    // Lago::RedisConfigBuilder cache connection
    'redis_cache_url' => env('LAGO_REDIS_CACHE_URL'),

    // Auth::Superset::Client (app/services/auth/superset/client.rb) — the
    // premium analytics Superset instance the dashboards query and the guest
    // token mutation talk to. Rails reads ENV["SUPERSET_*"] directly.
    'superset' => [
        'url' => env('SUPERSET_URL'),
        'username' => env('SUPERSET_USERNAME'),
        'password' => env('SUPERSET_PASSWORD'),
    ],

    'version' => $lagoVersion,

    'github_url' => $lagoGithubUrl,
];
