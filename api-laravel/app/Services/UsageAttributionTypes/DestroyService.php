<?php

declare(strict_types=1);

namespace App\Services\UsageAttributionTypes;

use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Models\UsageAttributionType;

/**
 * Port of Rails' UsageAttributionTypes::DestroyService — discards the type
 * and all its attributed values in one transaction (Discard → deleted_at).
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?UsageAttributionType $usageAttributionType,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('usage_attribution_type');

        if ($this->usageAttributionType === null) {
            return $result->notFoundFailure('usage_attribution_type');
        }

        /** @var UsageAttributionType $type */
        $type = $this->usageAttributionType;

        DB::transaction(function () use ($type): void {
            $type->usageAttributionValues()->withTrashed()->get()->each->delete();

            $type->delete();
        });

        $result->usage_attribution_type = $type;

        return $result;
    }
}
