<?php

declare(strict_types=1);

namespace App\Services\DunningCampaigns;

use App\Models\Customer;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\DunningCampaign;
use Illuminate\Support\Facades\DB;
use App\Models\DunningCampaignThreshold;

/**
 * Port of Rails' DunningCampaigns::UpdateService
 * (app/services/dunning_campaigns/update_service.rb) — the campaign
 * attributes plus full threshold-set replacement:
 *
 * - thresholds not present in the payload are discarded (SoftDeletes);
 * - thresholds with an id are updated, without one are created;
 * - when the threshold set changed, customers currently running this
 *   campaign whose overdue balances no longer reach any threshold have
 *   their attempt bookkeeping reset (Rails' reset_customers_if_no_threshold_match);
 * - the legacy applied_to_organization update flips the default billing
 *   entity's applied campaign (Rails' "remove when FE is released" TODO).
 */
class UpdateService extends BaseService
{
    /** Rails: permitted_attributes. */
    private const PERMITTED = ['name', 'bcc_emails', 'code', 'description', 'days_between_attempts', 'max_attempts'];

    public function __construct(
        private readonly object $organization,
        private readonly ?DunningCampaign $dunningCampaign,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('dunning_campaign');

        /** @var Organization $organization */
        $organization = $this->organization;

        if (! $organization->autoDunningEnabled()) {
            return $result->forbiddenFailure();
        }

        if ($this->dunningCampaign === null) {
            return $result->notFoundFailure('dunning_campaign');
        }

        $campaign = $this->dunningCampaign;

        $attributes = array_intersect_key($this->params, array_flip(self::PERMITTED));

        if (array_key_exists('bcc_emails', $attributes)) {
            $attributes['bcc_emails'] = array_values((array) ($attributes['bcc_emails'] ?? []));
        }

        $thresholdsChanged = false;

        // Rails: dunning_campaign.assign_attributes(permitted_attributes) —
        // validated together with the thresholds before any write.
        $campaign->fill($attributes);

        $thresholds = null;

        if (array_key_exists('thresholds', $this->params)) {
            $thresholds = [];

            foreach ((array) $this->params['thresholds'] as $thresholdInput) {
                $thresholdInput = (array) $thresholdInput;

                // Rails: find_or_initialize_by(id:) { organization_id }.
                $threshold = $campaign->thresholds()
                    ->where('id', $thresholdInput['id'] ?? null)
                    ->first()
                    ?? new DunningCampaignThreshold([
                        'dunning_campaign_id' => $campaign->id,
                        'organization_id' => $organization->id,
                    ]);

                $threshold->fill([
                    'currency' => $thresholdInput['currency'] ?? $threshold->currency,
                    'amount_cents' => $thresholdInput['amount_cents'] ?? $threshold->amount_cents,
                ]);

                // Rails: thresholds_updated ||= threshold.changed? &&
                //   threshold.persisted?; a discarded (missing) input is also
                //   an update.
                $thresholdsChanged = $thresholdsChanged
                    || ($threshold->exists && $threshold->isDirty());

                $thresholds[] = $threshold;
            }

            // Rails: discarded_thresholds = thresholds.where.not(id:).discard_all.
            $inputThresholdIds = array_values(array_filter(
                array_map(fn ($t) => is_array($t) ? ($t['id'] ?? null) : null, $this->params['thresholds']),
            ));

            $discardedQuery = $campaign->thresholds();

            if ($inputThresholdIds !== []) {
                $discardedQuery->whereNotIn('id', $inputThresholdIds);
            }

            $thresholdsChanged = $thresholdsChanged || $discardedQuery->exists();
        }

        $errors = $campaign->validateAttributes();

        foreach ($thresholds ?? [] as $threshold) {
            $errors += $threshold->validateAttributes();
        }

        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        DB::transaction(function () use ($organization, $campaign, $thresholds, $thresholdsChanged): void {
            $campaign->save();

            if ($thresholds !== null) {
                $inputThresholdIds = array_values(array_filter(array_map(
                    fn ($t) => is_array($t) ? ($t['id'] ?? null) : null,
                    $this->params['thresholds'],
                )));

                $discarded = $campaign->thresholds();

                if ($inputThresholdIds !== []) {
                    $discarded->whereNotIn('id', $inputThresholdIds);
                }

                // Rails: Discard (deleted_at stamp), no destroy callbacks.
                $discarded->toBase()->update(['deleted_at' => now()]);

                foreach ($thresholds as $threshold) {
                    $threshold->save();
                }
            }

            // Rails TODO: remove this when FE handles applied on billing entity.
            if (array_key_exists('applied_to_organization', $this->params)) {
                $newId = ! empty($this->params['applied_to_organization']) ? $campaign->id : null;

                $defaultBillingEntity = $organization->defaultBillingEntity;

                if ($defaultBillingEntity !== null
                    && $defaultBillingEntity->applied_dunning_campaign_id !== $newId) {
                    $defaultBillingEntity->forceFill([
                        'applied_dunning_campaign_id' => $newId,
                    ])->save();

                    $defaultBillingEntity->resetCustomersLastDunningCampaignAttempt();
                }
            }

            if ($thresholdsChanged) {
                $this->resetCustomersIfNoThresholdMatch($campaign);
            }
        });

        $result->dunning_campaign = $campaign;

        return $result;
    }

    /**
     * Rails: reset_customers_if_no_threshold_match — reset the attempt
     * bookkeeping of the campaign's customers whose overdue balances no
     * longer reach any of the (new) thresholds.
     */
    private function resetCustomersIfNoThresholdMatch(DunningCampaign $campaign): void
    {
        Customer::query()
            ->where(function ($applied) use ($campaign): void {
                $applied->where('applied_dunning_campaign_id', $campaign->id);
            })
            ->orWhere(function ($fallback) use ($campaign): void {
                $fallback->whereNull('applied_dunning_campaign_id')
                    ->where('exclude_from_dunning_campaign', false)
                    ->whereIn('billing_entity_id', $campaign->billingEntities()->select('id'));
            })
            ->whereExists(function ($sub): void {
                // Rails: .where(invoices: {payment_overdue: true}).
                $sub->selectRaw(1)
                    ->from('invoices')
                    ->whereColumn('invoices.customer_id', 'customers.id')
                    ->where('invoices.payment_overdue', true);
            })
            ->get()
            ->each(function (Customer $customer) use ($campaign): void {
                $balances = $customer->overdueBalances();

                $thresholdMatches = $campaign->thresholds->contains(
                    fn (DunningCampaignThreshold $threshold): bool => ($balances[$threshold->currency] ?? 0) >= (int) $threshold->amount_cents,
                );

                if (! $thresholdMatches) {
                    $customer->forceFill([
                        'dunning_currency_attempts' => [],
                        'last_dunning_campaign_attempt' => 0,
                        'last_dunning_campaign_attempt_at' => null,
                    ])->save();
                }
            });
    }
}
