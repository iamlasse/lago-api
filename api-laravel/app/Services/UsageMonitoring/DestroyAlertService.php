<?php

declare(strict_types=1);

namespace App\Services\UsageMonitoring;

use App\Services\BaseResult;
use Illuminate\Support\Facades\DB;
use App\Models\UsageMonitoring\Alert;
use App\Models\UsageMonitoring\AlertThreshold;

/**
 * Port of Rails' UsageMonitoring::DestroyAlertService
 * (app/services/usage_monitoring/destroy_alert_service.rb) — thresholds are
 * hard-deleted, the alert is discarded (deleted_at).
 */
class DestroyAlertService extends BaseService
{
    public function __construct(private readonly ?Alert $alert)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('alert');

        if ($this->alert === null) {
            return $result->notFoundFailure('alert');
        }

        DB::transaction(function (): void {
            // Rails: alert.with_lock.
            $alert = Alert::query()->whereKey($this->alert->id)->lockForUpdate()->first() ?? $this->alert;

            AlertThreshold::query()
                ->where('usage_monitoring_alert_id', $alert->id)
                ->delete();

            $alert->delete();
        });

        $result->alert = $this->alert;

        return $result;
    }
}
