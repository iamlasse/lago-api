<?php

declare(strict_types=1);

namespace App\Services\DunningCampaigns;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\DunningCampaign;
use Illuminate\Support\Facades\DB;
use App\Models\DunningCampaignThreshold;

/**
 * Port of Rails' DunningCampaigns::CreateService
 * (app/services/dunning_campaigns/create_service.rb).
 *
 * Rails order: organization.auto_dunning_enabled? (premium +
 * "auto_dunning" premium integration), thresholds mandatory, then in one
 * transaction — demote any other applied_to_organization campaign (and
 * reset the default billing entity's customers' attempt bookkeeping),
 * create the campaign with its nested thresholds, and when
 * applied_to_organization was requested, attach it to the default billing
 * entity.
 *
 * Validation errors are the Rails model errors (name presence, bcc_emails
 * email format, positive days_between_attempts / max_attempts, org-scoped
 * code uniqueness over kept rows, threshold amount/currency/uniqueness) —
 * record_validation_failure envelope. The port validates before the writes
 * so a failure leaves nothing persisted (Rails' save! + rollback).
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly object $organization,
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

        if (($this->params['thresholds'] ?? null) === null || $this->params['thresholds'] === []) {
            return $result->validationFailure(['thresholds' => ["can't be blank"]]);
        }

        $campaign = new DunningCampaign([
            'organization_id' => $organization->id,
            'code' => $this->params['code'] ?? null,
            'bcc_emails' => array_values((array) ($this->params['bcc_emails'] ?? [])),
            'days_between_attempts' => $this->params['days_between_attempts'] ?? 1,
            'max_attempts' => $this->params['max_attempts'] ?? 1,
            'name' => $this->params['name'] ?? null,
            'description' => $this->params['description'] ?? null,
            // Rails keeps the legacy column in sync through the
            // applied_to_organization create path (the model ignores it).
            'applied_to_organization' => (bool) ($this->params['applied_to_organization'] ?? false),
        ]);

        $thresholds = [];

        foreach ($this->params['thresholds'] as $thresholdInput) {
            $thresholds[] = new DunningCampaignThreshold([
                'dunning_campaign_id' => $campaign->id,
                'organization_id' => $organization->id,
                'currency' => $thresholdInput['currency'] ?? null,
                'amount_cents' => $thresholdInput['amount_cents'] ?? null,
            ]);
        }

        $errors = $campaign->validateAttributes();

        foreach ($thresholds as $threshold) {
            $errors += $threshold->validateAttributes();
        }

        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $campaign = DB::transaction(function () use ($organization, $campaign, $thresholds): DunningCampaign {
            if (! empty($this->params['applied_to_organization'])) {
                // Rails: organization.dunning_campaigns.applied_to_organization
                //   .update_all(applied_to_organization: false).
                DunningCampaign::query()
                    ->where('organization_id', $organization->id)
                    ->where('applied_to_organization', true)
                    ->toBase()->update(['applied_to_organization' => false]);

                $organization->defaultBillingEntity
                    ->resetCustomersLastDunningCampaignAttempt();
            }

            $campaign->save();

            foreach ($thresholds as $threshold) {
                // The uuid FK only exists once the campaign row is saved
                // (Rails' thresholds_attributes assignment).
                $threshold->dunning_campaign_id = $campaign->id;
                $threshold->save();
            }

            if (! empty($this->params['applied_to_organization'])) {
                $organization->defaultBillingEntity->forceFill([
                    'applied_dunning_campaign_id' => $campaign->id,
                ])->save();
            }

            return $campaign;
        });

        $result->dunning_campaign = $campaign;

        return $result;
    }
}
