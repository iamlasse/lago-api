<?php

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
$lagoVersionContent = is_file($lagoVersionFile) ? trim((string) file_get_contents($lagoVersionFile)) : null;

$lagoVersion = match (true) {
    $lagoVersionContent === null => env('APP_ENV', 'production'),
    strlen($lagoVersionContent) === 40 => date('Y-m-d', (int) filectime($lagoVersionFile)),
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

    // app/services/utils/pdf_generator.rb (Gotenberg)
    'pdf_url' => env('LAGO_PDF_URL'),

    'webhook' => [
        // app/services/webhooks/send_http_service.rb
        'timeout_seconds' => (int) env('LAGO_WEBHOOK_TIMEOUT_SECONDS', 30),
        'attempts' => (int) env('LAGO_WEBHOOK_ATTEMPTS', 3),
        // lib/lago_http_client/lago_http_client/address_guard.rb
        'allow_private_urls' => (bool) env('LAGO_WEBHOOK_ALLOW_PRIVATE_URLS', false),
    ],

    // Lago::RedisConfigBuilder cache connection
    'redis_cache_url' => env('LAGO_REDIS_CACHE_URL'),

    'version' => $lagoVersion,

    'github_url' => $lagoGithubUrl,
];
