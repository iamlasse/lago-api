<?php

declare(strict_types=1);

namespace App\Services\BillingSegments;

use App\Models\PricingUnit;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\BillingSegment;
use App\Models\ContractRateCard;
use App\Enums\BillingSegmentStatus;
use App\Services\Billing\BillableSegment;

/**
 * Port of Rails' BillingSegments::CreateService (app/services/
 * billing_segments/create_service.rb) — writes a card's calendar slices as
 * rows, in one statement.
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly ContractRateCard $contractRateCard,
        /** @var list<BillableSegment> */
        private readonly array $billableSegments,
        private readonly ?PricingUnit $pricingUnit,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('billing_segments');

        // insert_all! rather than a conflict clause: a duplicate here means
        // the caller wrote without subtracting what is already stored, and
        // that is a bug to see, not to absorb (Rails: insert_all!, which
        // raises on conflict; the overlap EXCLUDE constraint keeps the
        // failure loud here too).
        $rows = array_map(fn (BillableSegment $segment): array => $this->rowFor($segment), $this->billableSegments);

        $result->billing_segments = $this->insertAll($rows);

        return $result;
    }

    /**
     * Column values, not associations: the row is written without loading
     * anything (Rails `#row_for`).
     *
     * @return array<string, mixed>
     */
    private function rowFor(BillableSegment $billableSegment): array
    {
        $rateCard = $this->contractRateCard->rateCard;
        $product = $rateCard->product;

        $status = $product->metered() && $rateCard->advance()
            ? BillingSegmentStatus::Processing
            : BillingSegmentStatus::Pending;

        return [
            'organization_id' => $this->contractRateCard->organization_id,
            'contract_id' => $this->contractRateCard->contract_id,
            'customer_id' => $this->contractRateCard->contract->customer_id,
            'contract_rate_card_id' => $this->contractRateCard->id,
            // Query-builder insert binds with the grammar's second-precision
            // format — format the microseconds explicitly (the inclusive end
            // carries .999999).
            'cycle_started_at' => $billableSegment->cycleStartedAt->format('Y-m-d H:i:s.u'),
            'started_at' => $billableSegment->startedAt->format('Y-m-d H:i:s.u'),
            'ended_at' => BillingSegment::inclusiveEnd($billableSegment->endedAt)->format('Y-m-d H:i:s.u'),
            'billing_at' => $billableSegment->billingAt->format('Y-m-d H:i:s.u'),
            'rate_card_rate_id' => $billableSegment->rate?->id,
            'rate_override_id' => $billableSegment->rateOverride?->id,
            'rate_properties' => json_encode($billableSegment->properties()),
            'currency' => $rateCard->currency,
            'pricing_unit_id' => $this->pricingUnit?->id,
            'proration_ratio' => $billableSegment->prorationRatio,
            'status' => $status->value,
            // Rails' insert_all! stamps these from the model; the bulk write
            // carries them itself.
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Rails re-instantiates the RETURNING rows as models
     * (`BillingSegment.instantiate`). Laravel's bulk insert has no RETURNING
     * into Eloquent, so the written rows are re-read by their natural key —
     * one extra query, the same model objects out.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<BillingSegment>
     */
    private function insertAll(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        BillingSegment::query()->insert($rows);

        $startedAts = array_map(
            fn (array $row): string => (string) $row['started_at'],
            $rows,
        );

        return BillingSegment::query()
            ->where('contract_rate_card_id', $this->contractRateCard->id)
            ->whereIn('started_at', $startedAts)
            ->orderBy('started_at')
            ->get()
            ->all();
    }
}
