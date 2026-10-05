<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Port of Rails' db/seeds.rb: loads the numbered dev seeders in filename
 * order (api/db/seeds/*.rb), mirroring the upstream dataset the frontend is
 * developed against.
 *
 * Rails' 01_base.rb forces premium on for the whole run
 * (License.instance_variable_set(:@premium, true)); the license is normally
 * config-driven (App\Support\License) but BaseService::premium() still reads
 * the env directly, so both are set here.
 *
 * NOTE: no WithoutModelEvents — model lifecycle hooks (hmac_key/slug on
 * Organization, document number prefixes) must fire during seeding, like
 * upstream.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Rails' 01_base.rb forces premium on for the whole run — the port
        // must force it through BOTH layers the premium gates read
        // (App\Support\License's config and BaseService's env read).
        // RESTORE the previous value afterwards (never just unset): in the
        // test process phpunit.xml pins LAGO_LICENSE=null, and a blind unset
        // would let the next app fall back to the developer's .env
        // (LAGO_LICENSE=dev-license) — silently flipping the whole suite
        // premium from this seeder on.
        $previousLicense = getenv('LAGO_LICENSE');

        config(['lago.license' => 'dev-seed-license']);
        putenv('LAGO_LICENSE=dev-seed-license');
        $_ENV['LAGO_LICENSE'] = 'dev-seed-license';
        $_SERVER['LAGO_LICENSE'] = 'dev-seed-license';

        try {
            $this->call([
                BaseSeeder::class,
                JohnDoeSeeder::class,
                EntitlementsSeeder::class,
                AlertingSeeder::class,
                SecurityLogsSeeder::class,
                ProgressiveBillingSeeder::class,
                SubscriptionsSeeder::class,
                EventsSeeder::class,
                ProductCatalogSeeder::class,
                InvoicesSeeder::class,
                EmailActivityLogsSeeder::class,
                OrderFormsSeeder::class,
            ]);
        } finally {
            // Do not leak the forced license into a long-lived process.
            if ($previousLicense === false) {
                putenv('LAGO_LICENSE');
                unset($_ENV['LAGO_LICENSE'], $_SERVER['LAGO_LICENSE']);
            } else {
                putenv('LAGO_LICENSE='.$previousLicense);
                $_ENV['LAGO_LICENSE'] = $previousLicense;
                $_SERVER['LAGO_LICENSE'] = $previousLicense;
            }
        }
    }
}
