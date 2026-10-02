<?php

declare(strict_types=1);

namespace App\Services\FixedCharges;

use App\Models\Plan;
use App\Models\AddOn;
use App\Services\BaseResult;

/**
 * Port of Rails' FixedCharges::GenerateCodeService.
 */
class GenerateCodeService extends \App\Services\BaseService
{
    public function __construct(
        private readonly Plan $plan,
        private readonly AddOn $addOn,
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
        $baseCode = $this->addOn->code;

        if (! $this->plan->fixedCharges()->parents()->where('code', $baseCode)->exists()) {
            return $baseCode;
        }

        $existingSuffixes = $this->plan->fixedCharges()
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
