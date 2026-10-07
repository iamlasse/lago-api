<?php

declare(strict_types=1);

use App\Models\InboundWebhook;
use App\Jobs\Clock\InboundWebhooksCleanupJob;

uses()->group('ledger:job:Clock.InboundWebhooksCleanupJob');

/**
 * Port of Rails' spec/jobs/clock/inbound_webhooks_cleanup_job_spec.rb — the
 * daily purge removes inbound webhooks older than 90 days.
 */
it('removes old inbound webhooks', function (): void {
    InboundWebhook::factory()->create(['updated_at' => now()->subDays(91)]);

    (new InboundWebhooksCleanupJob)->handle();

    expect(InboundWebhook::query()->count())->toBe(0);
});

it('keeps recent inbound webhooks', function (): void {
    InboundWebhook::factory()->create(['updated_at' => now()->subDays(89)]);

    (new InboundWebhooksCleanupJob)->handle();

    expect(InboundWebhook::query()->count())->toBe(1);
});
