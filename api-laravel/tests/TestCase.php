<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Rails' ActiveJob test adapter only records enqueued jobs — it never
        // executes them. The Laravel equivalent is Queue::fake(): without it
        // the `sync` test connection runs dispatched jobs inline mid-request,
        // a semantic no Rails spec relies on.
        Queue::fake();
    }
}
