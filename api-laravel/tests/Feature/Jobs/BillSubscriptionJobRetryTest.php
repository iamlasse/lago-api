<?php

declare(strict_types=1);

/**
 * Port of spec/jobs/bill_subscription_job_spec.rb #perform failure
 * scenarios, where Rails stubs Invoices::SubscriptionService with
 * `allow(...).to receive(:call)`.
 *
 * PHP has no per-test static stubbing: a Mockery alias mock intercepts
 * `SubscriptionService::call` but must be declared before the real class
 * loads and then permanently occupies the class name for the rest of the
 * process. The scenarios therefore run in an isolated child PHP process
 * (tests/Feature/Jobs/retry-scenarios.php) against the same migrated test
 * database; this test only spawns it and asserts the exit code.
 */
it('passes the BillSubscriptionJob retry scenarios in an isolated process', function (): void {
    $database = (string) config('database.connections.'.config('database.default').'.database');

    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __DIR__.'/retry-scenarios.php', $database],
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );

    expect($process)->toBeResource();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    expect($exitCode)->toBe(0, "retry scenarios failed\n--- stdout ---\n{$stdout}\n--- stderr ---\n{$stderr}");

    // All five scenarios reported OK (mirrors the Rails spec's per-context
    // expectations, including `have_received(:call)`).
    expect(mb_substr_count((string) $stdout, 'OK '))->toBe(5);
})->group('ledger:job:BillSubscriptionJob');
