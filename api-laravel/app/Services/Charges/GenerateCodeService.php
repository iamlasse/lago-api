<?php

declare(strict_types=1);

namespace App\Services\Charges;

use App\Models\Plan;
use App\Services\BaseResult;
use App\Models\BillableMetric;

/**
 * Port of Rails' Charges::GenerateCodeService.
 */
class GenerateCodeService extends \App\Services\BaseService
{
    public function __construct(
        private readonly Plan $plan,
        private readonly BillableMetric $billableMetric,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('code');
        $result->code = $this->generateUniqueCode();

        return $result;
    }

    private function generateUniqueCode(): string
    {
        $baseCode = $this->billableMetric->code;

        if (! $this->plan->charges()->parents()->where('code', $baseCode)->exists()) {
            return $baseCode;
        }

        $existingSuffixes = $this->plan->charges()
            ->parents()
            ->whereRaw('code ~ ?', ['^'.preg_quote($baseCode, '/').'_\d+$'])
            ->pluck('code')
            ->map(fn (string $code) => (int) str_starts_with($code, $baseCode.'_')
                ? (int) mb_substr($code, mb_strlen($baseCode) + 1)
                : 0);

        $nextSuffix = ($existingSuffixes->max() ?: 1) + 1;

        return $baseCode.'_'.$nextSuffix;
    }
}
