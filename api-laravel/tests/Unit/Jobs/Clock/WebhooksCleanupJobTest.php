<?php

declare(strict_types=1);

use App\Models\Webhook;
use App\Jobs\Clock\WebhooksCleanupJob;
use Database\Factories\WebhookFactory;

uses()->group('ledger:job:Clock.WebhooksCleanupJob');

/**
 * Port of Rails' spec/jobs/clock/webhooks_cleanup_job_spec.rb — the daily
 * purge removes webhooks older than the retention period in batches.
 */
function cleanupWebhook(array $attributes = []): Webhook
{
    /** @var WebhookFactory $factory */
    $factory = Webhook::factory();

    return $factory->succeeded()->state(fn (): array => $attributes)->create();
}

it('removes webhooks older than the retention period', function (): void {
    cleanupWebhook(['updated_at' => now()->subDays(100)]);

    (new WebhooksCleanupJob)->handle();

    expect(Webhook::query()->count())->toBe(0);
});

it('keeps webhooks newer than the retention period', function (): void {
    cleanupWebhook(['updated_at' => now()->subDays(89)]);

    (new WebhooksCleanupJob)->handle();

    expect(Webhook::query()->count())->toBe(1);
});

it('processes multiple batches', function (): void {
    WebhooksCleanupJob::$batchSize = 2;

    cleanupWebhook(['updated_at' => now()->subDays(100)]);
    cleanupWebhook(['updated_at' => now()->subDays(101)]);
    cleanupWebhook(['updated_at' => now()->subDays(102)]);
    $recent = cleanupWebhook(['updated_at' => now()->subDays(89)]);

    (new WebhooksCleanupJob)->handle();

    expect(Webhook::query()->count())->toBe(1)
        ->and(Webhook::query()->first()->id)->toBe($recent->id);

    WebhooksCleanupJob::$batchSize = 1000;
});

it('honors a custom retention period', function (): void {
    WebhooksCleanupJob::$retentionDays = 30;

    cleanupWebhook(['updated_at' => now()->subDays(31)]);
    $kept = cleanupWebhook(['updated_at' => now()->subDays(29)]);

    (new WebhooksCleanupJob)->handle();

    expect(Webhook::query()->count())->toBe(1)
        ->and(Webhook::query()->first()->id)->toBe($kept->id);

    WebhooksCleanupJob::$retentionDays = 90;
});
