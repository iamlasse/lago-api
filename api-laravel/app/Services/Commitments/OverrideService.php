<?php

declare(strict_types=1);

namespace App\Services\Commitments;

use App\Support\License;
use App\Models\Commitment;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' Commitments::OverrideService
 * (app/services/commitments/override_service.rb) — duplicates a plan's
 * minimum commitment onto an override plan with the negotiated amount.
 */
class OverrideService extends BaseService
{
    public function __construct(
        private readonly ?Commitment $commitment,
        /** @var array<string, mixed> */
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('commitment');

        if (! License::premium() || $this->commitment === null) {
            return $result;
        }

        try {
            DB::transaction(function () use ($result): void {
                $params = $this->params;

                /** @var Commitment $newCommitment */
                $newCommitment = $this->commitment->replicate();

                if (array_key_exists('amount_cents', $params)) {
                    $newCommitment->amount_cents = $params['amount_cents'];
                }

                if (array_key_exists('invoice_display_name', $params)) {
                    $newCommitment->invoice_display_name = $params['invoice_display_name'];
                }

                $newCommitment->plan_id = $params['plan_id'];

                $newCommitment->save();

                if (array_key_exists('tax_codes', $params)) {
                    ApplyTaxesService::call(
                        commitment: $newCommitment,
                        taxCodes: (array) $params['tax_codes'],
                    )->raiseIfError();
                }

                $result->commitment = $newCommitment;
            });
        } catch (FailedResult $e) {
            return $e->result;
        }

        return $result;
    }
}
