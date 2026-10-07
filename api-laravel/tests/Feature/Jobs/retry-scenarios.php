<?php

declare(strict_types=1);

/**
 * Child-process runner for the BillSubscriptionJob retry scenarios
 * (spec/jobs/bill_subscription_job_spec.rb #perform failure contexts, where
 * Rails stubs Invoices::SubscriptionService).
 *
 * PHP cannot stub a static service entrypoint inside the running process,
 * and a Mockery alias mock permanently occupies the class name — so these
 * scenarios run in their own PHP process, spawned by
 * tests/Feature/Jobs/BillSubscriptionJobRetryTest.php. The child:
 *  - boots the framework against the same (already migrated) test database,
 *  - declares the alias mock BEFORE anything loads the real service class,
 *  - wraps every scenario in its own transaction and rolls back.
 *
 * Exit code 0 = all scenarios passed; failures print the scenario name.
 */

require __DIR__.'/../../../vendor/autoload.php';

foreach ([
    'DB_CONNECTION' => 'pgsql',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '5433',
    'DB_USERNAME' => 'postgres',
    'DB_PASSWORD' => 'postgres',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
] as $key => $value) {
    putenv($key.'='.$value);
}
putenv('DB_DATABASE='.($argv[1] ?? 'lago_test_r'));

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;

DB::beginTransaction();

/**
 * One alias mock for the whole process — the class name it occupies cannot
 * be re-declared, so scenarios queue their expectations on this instance.
 */
$serviceMock = Mockery::mock('alias:'.App\Services\Invoices\SubscriptionService::class);

function retryFixture(): array
{
    $organization = App\Models\Organization::factory()->create();
    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $plan = App\Models\Plan::factory()->create(['organization_id' => $organization->id]);
    $subscription = App\Models\Subscription::factory()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
        'status' => 'active',
        'external_id' => 'sub-retry-1',
    ]);

    return compact('organization', 'customer', 'plan', 'subscription');
}

function retryInvoice(array $f, App\Enums\InvoiceStatus $status): App\Models\Invoice
{
    return App\Models\Invoice::factory()->create([
        'organization_id' => $f['organization']->id,
        'customer_id' => $f['customer']->id,
        'currency' => 'EUR',
        'status' => $status,
    ]);
}

function retryFailedResult(): App\Services\BaseResult
{
    $result = App\Services\BaseResult::of('invoice');
    $result->validationFailure(['base' => ['generation_failed']]);

    return $result;
}

function expectThrows(callable $fn, string $class, string $message): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (! $e instanceof $class) {
            throw new RuntimeException($message.' — expected '.$class.', got '.$e::class);
        }

        return;
    }

    throw new RuntimeException($message.' — no exception thrown');
}

function retryJob(array $f, ?string $invoiceId = null): App\Jobs\BillSubscriptionJob
{
    return new App\Jobs\BillSubscriptionJob([$f['subscription']], 1791241200, 'subscription_starting', $invoiceId);
}

$scenarios = [
    'success calls the service and does not retry' => function () use ($serviceMock): void {
        $f = retryFixture();
        $serviceMock->shouldReceive('call')->once()->andReturn(App\Services\BaseResult::of('invoice'));

        Bus::fake([App\Jobs\BillSubscriptionJob::class]);
        retryJob($f)->handle();

        Bus::assertNotDispatched(App\Jobs\BillSubscriptionJob::class);
    },

    'retries with the invoice when a generating invoice is attached' => function () use ($serviceMock): void {
        $f = retryFixture();
        $resultInvoice = retryInvoice($f, App\Enums\InvoiceStatus::Generating);

        $result = retryFailedResult();
        $result->invoice = $resultInvoice;
        $serviceMock->shouldReceive('call')->once()->andReturn($result);

        Bus::fake([App\Jobs\BillSubscriptionJob::class]);
        retryJob($f)->handle();

        Bus::assertDispatched(fn(App\Jobs\BillSubscriptionJob $job): bool => $job->invoiceId === $resultInvoice->id
            && $job->subscriptions[0]->id === $f['subscription']->id
            && $job->timestamp === 1791241200
            && $job->invoicingReason === 'subscription_starting'
            && $job->skipCharges === false);
    },

    'raises when the invoice was passed as an argument' => function () use ($serviceMock): void {
        $f = retryFixture();
        $passedInvoice = retryInvoice($f, App\Enums\InvoiceStatus::Generating);

        $result = retryFailedResult();
        $result->invoice = $passedInvoice;
        $serviceMock->shouldReceive('call')->once()->andReturn($result);

        Bus::fake([App\Jobs\BillSubscriptionJob::class]);

        expectThrows(
            fn () => retryJob($f, $passedInvoice->id)->handle(),
            App\Services\Failures\FailedResult::class,
            'invoice passed as argument should raise',
        );
        Bus::assertNotDispatched(App\Jobs\BillSubscriptionJob::class);
    },

    'raises when the attached failure invoice is not generating' => function () use ($serviceMock): void {
        $f = retryFixture();
        $draftInvoice = retryInvoice($f, App\Enums\InvoiceStatus::Draft);

        $result = retryFailedResult();
        $result->invoice = $draftInvoice;
        $serviceMock->shouldReceive('call')->once()->andReturn($result);

        Bus::fake([App\Jobs\BillSubscriptionJob::class]);

        expectThrows(
            fn () => retryJob($f)->handle(),
            App\Services\Failures\FailedResult::class,
            'non-generating attached invoice should raise',
        );
        Bus::assertNotDispatched(App\Jobs\BillSubscriptionJob::class);
    },

    'raises when the failure carries no invoice at all' => function () use ($serviceMock): void {
        $f = retryFixture();
        $serviceMock->shouldReceive('call')->once()->andReturn(retryFailedResult());

        Bus::fake([App\Jobs\BillSubscriptionJob::class]);

        expectThrows(
            fn () => retryJob($f)->handle(),
            App\Services\Failures\FailedResult::class,
            'invoice-less failure should raise',
        );
        Bus::assertNotDispatched(App\Jobs\BillSubscriptionJob::class);
    },
];

try {
    foreach ($scenarios as $name => $scenario) {
        try {
            $scenario();
            DB::rollBack();
            DB::beginTransaction();
            echo 'OK '.$name."\n";
        } catch (Throwable $e) {
            throw new RuntimeException('Scenario "'.$name.'": '.$e->getMessage(), 0, $e);
        }
    }
} finally {
    DB::rollBack();
}

exit(0);
