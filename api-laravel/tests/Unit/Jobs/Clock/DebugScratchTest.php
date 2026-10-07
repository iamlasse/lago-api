<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use App\Jobs\Clock\TerminateEndedSubscriptionsJob;
use App\Jobs\Subscriptions\TerminateEndedSubscriptionJob;

it('debugs the pushed job', function (): void {
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
        var_dump(get_class($record));
        $props = array_keys(get_object_vars($record));
        var_dump($props);
        var_dump($record->subscription);
    }
    expect(true)->toBeTrue();
});
