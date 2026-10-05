<?php

declare(strict_types=1);

namespace App\Services\UsageMonitoring\Alerts;

use App\Models\Wallet;
use App\Models\Subscription;
use App\Services\BaseResult;
use Illuminate\Support\Facades\DB;
use App\Models\UsageMonitoring\Alert;
use App\Models\UsageMonitoring\AlertThreshold;

/**
 * Port of Rails' UsageMonitoring::Alerts::DestroyAllService
 * (app/services/usage_monitoring/alerts/destroy_all_service.rb) — discards
 * every alert of the alertable. Alert rows are locked first, in a stable
 * order, or this deadlocks against evaluation and edits.
 */
class DestroyAllService extends \App\Services\UsageMonitoring\BaseService
{
    public function __construct(private readonly Subscription|Wallet|null $alertable)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('alerts');

        if ($this->alertable === null) {
            return $result->notFoundFailure('alertable');
        }

        $alertIds = $this->alertable->alerts()->pluck('id')->all();

        DB::transaction(function () use ($alertIds): void {
            if ($alertIds !== []) {
                Alert::query()->whereIn('id', $alertIds)->orderBy('id')->lockForUpdate()->pluck('id');
            }

            if ($alertIds !== []) {
                AlertThreshold::query()
                    ->whereIn('usage_monitoring_alert_id', $alertIds)
                    ->delete();

                Alert::query()->whereIn('id', $alertIds)->update(['deleted_at' => now()]);
            }
        });

        return $result;
    }
}
