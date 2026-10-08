<?php

declare(strict_types=1);

namespace App\Services\BillingSegments;

use Carbon\CarbonImmutable;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\ContractRateCard;
use App\Services\Billing\BillableSegment;
use App\Services\Billing\RateCards\Schedule;

/**
 * Port of Rails' BillingSegments::MissingBillableSegmentsService
 * (app/services/billing_segments/missing_billable_segments_service.rb) —
 * of what a card owes by a given instant, which pieces are not stored yet.
 * What comes in is the calendar's answer, and it can include pieces an
 * earlier run already wrote.
 *
 *   already stored     in, from the calendar         out
 *   (nothing)          Jan 1-Feb 1, Feb 1-Feb 15     both
 *   Feb 1-Feb 15       Feb 1-Feb 15, Feb 15-Mar 1    Feb 15-Mar 1
 *   Feb 1-Mar 1        Feb 1-Feb 15, Feb 15-Mar 1    (nothing)
 */
class MissingBillableSegmentsService extends BaseService
{
    public function __construct(
        private readonly ContractRateCard $contractRateCard,
        private readonly Schedule $schedule,
        private readonly CarbonImmutable $timestamp,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('billable_segments');

        $due = $this->schedule->segmentsDueBy($this->timestamp);

        if ($due === []) {
            $result->billable_segments = [];

            return $result;
        }

        $settled = $this->settledPeriods($due);

        // Overlap, not an equal start: row 3 above is what an equal-start
        // test gets wrong. The stored end is inclusive (a calendar boundary
        // minus one microsecond), so stored [c .. d] and candidate [a, b)
        // overlap iff a <= d && c < b — exactly Rails'
        // `(a...b).overlaps?(c..d)`.
        $result->billable_segments = array_values(array_filter(
            $due,
            function (BillableSegment $segment) use ($settled): bool {
                foreach ($settled as [$settledStart, $settledEnd]) {
                    if ($segment->startedAt->lte($settledEnd) && $settledStart->lt($segment->endedAt)) {
                        return false;
                    }
                }

                return true;
            },
        ));

        return $result;
    }

    /**
     * The second read of billing_segments — the first is the schedule's
     * resume_at, a scalar that cannot express a hole. The query it costs buys
     * a walk bounded by the last stored cycle rather than by the card's age,
     * which matters for a daily card.
     *
     * Only the stored periods that could touch the candidates:
     *
     *   due    [Feb 1 -> Feb 15), [Feb 15 -> Mar 1)   what the calendar says is owed
     *   window Feb 1 -> Mar 1                         their union
     *   out    [Feb 1 .. Feb 14 23:59:59.999]         the half a previous run already billed
     *
     * @param  list<BillableSegment>  $due
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function settledPeriods(array $due): array
    {
        $windowStart = null;
        $windowEnd = null;
        foreach ($due as $segment) {
            $windowStart = $windowStart === null ? $segment->startedAt : $windowStart->min($segment->startedAt);
            $windowEnd = $windowEnd === null ? $segment->endedAt : $windowEnd->max($segment->endedAt);
        }

        return $this->contractRateCard->billingSegments()
            ->where('started_at', '<', $windowEnd)
            ->where('ended_at', '>=', $windowStart)
            ->get(['started_at', 'ended_at'])
            ->map(fn ($segment): array => [
                CarbonImmutable::parse($segment->started_at),
                CarbonImmutable::parse($segment->ended_at),
            ])
            ->all();
    }
}
