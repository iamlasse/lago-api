<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use App\Jobs\Clock\TerminateEndedSubscriptionsJob;
use App\Jobs\Subscriptions\TerminateEndedSubscriptionJob;

it('debugs the pushed job id', function (): void {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2023-02-15 12:00:00'));

    $customer = Customer::factory()->create();
    $sub = Subscription::factory()->forCustomer($customer)->create([
        'ending_at' => Carbon::parse('2023-02-15 06:00:00'),
    ]);

    (new TerminateEndedSubscriptionsJob)->handle();

    Queue::assertPushed(TerminateEndedSubscriptionJob::class, 1);

    $pushed = Queue::pushed(TerminateEndedSubscriptionJob::class);
    foreach ($pushed as $record) {
        var_dump('job sub id: '.$record->subscription->id, 'sub id: '.$sub->id, 'equal: '.var_export($record->subscription->id === $sub->id, true));
    }
    expect(true)->toBeTrue();
});
