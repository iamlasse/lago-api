<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Invoice;
use App\Models\PaymentRequest;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Polymorphic type columns store Rails class names (payments.
        // payable_type = "Invoice" / "PaymentRequest") — map them to the
        // Laravel classes so morphTo relations resolve.
        Relation::morphMap([
            'Invoice' => Invoice::class,
            'PaymentRequest' => PaymentRequest::class,
        ]);
    }
}
