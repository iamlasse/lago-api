<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Port of Rails' db/seeds/21_events.rb: 52 events for the john-doe
 * subscription over the last 6 months (sum/count metering input).
 *
 * Rails-inherited non-idempotency: events are appended on every run
 * (transaction ids are random upstream too).
 */
class EventsSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->where('name', 'Hooli')->firstOrFail();

        // 6 month-offsets x (5 sum_bm + 2 count_bm) events.
        for ($month = 0; $month < 6; $month++) {
            for ($i = 0; $i < 5; $i++) {
                $this->createEvent($organization->id, 'sum_bm', now()->subMonths($month)->subDays(random_int(1, 20)));
            }

            for ($i = 0; $i < 2; $i++) {
                $this->createEvent($organization->id, 'count_bm', now()->subMonths($month)->subDays(random_int(1, 20)));
            }
        }

        // 5 sum_bm events with blanked properties.
        for ($i = 0; $i < 5; $i++) {
            $event = $this->createEvent($organization->id, 'sum_bm', now()->subDays(random_int(1, 10)));
            $event->properties = [];
            $event->save();
        }

        // 5 events with an unknown metric code.
        for ($i = 0; $i < 5; $i++) {
            $this->createEvent($organization->id, 'foo', now()->subDays(random_int(1, 10)));
        }
    }

    private function createEvent(string $organizationId, string $code, \Carbon\CarbonInterface $time): Event
    {
        return Event::create([
            'organization_id' => $organizationId,
            'external_customer_id' => 'cust_john-doe',
            'external_subscription_id' => 'sub_john-doe-main',
            'transaction_id' => 'tr_'.bin2hex(random_bytes(16)),
            'code' => $code,
            'properties' => ['custom_field' => 10],
            'metadata' => [
                'user_agent' => 'Lago Python v0.1.5',
                'ip_address' => fake()->ipv4(),
            ],
            'timestamp' => $time->clone()->subSeconds(random_int(0, 12)),
            'created_at' => $time,
        ]);
    }
}
