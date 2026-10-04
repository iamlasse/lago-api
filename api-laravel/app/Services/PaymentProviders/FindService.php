<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentProvider;

/**
 * Port of Rails' PaymentProviders::FindService — finds an organization's
 * provider by id, or by code (+ optional provider type slug). With several
 * providers and no code given, it fails with "payment_provider_code_missing";
 * with no match, "payment_provider_not_found" (a ServiceFailure, not a
 * validation error).
 */
class FindService extends BaseService
{
    public function __construct(
        private readonly string $organizationId,
        private readonly ?string $code = null,
        private readonly ?string $id = null,
        private readonly ?string $paymentProviderType = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment_provider');

        $scope = PaymentProvider::query()->where('organization_id', $this->organizationId);

        if ($this->paymentProviderType !== null && $this->paymentProviderType !== '') {
            $scope->where('type', PaymentProvider::slugToType($this->paymentProviderType));
        }

        if ($this->id !== null && $this->id !== '') {
            $provider = (clone $scope)->where('id', $this->id)->first();

            if ($provider !== null) {
                $result->payment_provider = $provider;

                return $result;
            }
        }

        if (($this->code === null || $this->code === '') && (clone $scope)->count() > 1) {
            return $result->serviceFailure(
                code: 'payment_provider_code_missing',
                message: 'Payment provider code is missing',
            );
        }

        if ($this->code !== null && $this->code !== '') {
            $scope->where('code', $this->code);
        }

        $provider = $scope->first();

        if ($provider === null) {
            return $result->serviceFailure(
                code: 'payment_provider_not_found',
                message: 'Payment provider not found',
            );
        }

        $result->payment_provider = $provider;

        return $result;
    }
}
