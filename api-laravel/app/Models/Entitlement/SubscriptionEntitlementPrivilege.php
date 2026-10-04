<?php

declare(strict_types=1);

namespace App\Models\Entitlement;

/**
 * Port of Rails' Entitlement::SubscriptionEntitlementPrivilege
 * (app/models/entitlement/subscription_entitlement_privilege.rb) — the
 * merged view of one privilege's plan value and subscription override.
 */
class SubscriptionEntitlementPrivilege
{
    public function __construct(
        public ?string $organizationId = null,
        public ?string $entitlementFeatureId = null,
        public ?string $code = null,
        public ?string $value = null,
        public ?string $valueType = null,
        public ?string $planValue = null,
        public ?string $subscriptionValue = null,
        public ?string $name = null,
        public mixed $config = null,
        public ?string $orderingDate = null,
        public ?string $planEntitlementId = null,
        public ?string $subEntitlementId = null,
        public ?string $planEntitlementValueId = null,
        public ?string $subEntitlementValueId = null,
    ) {}

    /**
     * Rails: `config` — a string value is parsed as JSON (the raw SQL rows
     * hand back the jsonb column as a string on some drivers).
     */
    public function config(): mixed
    {
        if (is_string($this->config)) {
            return json_decode($this->config, true);
        }

        return $this->config;
    }

    /**
     * Rails: `to_h` — the attributes hash with the parsed config.
     *
     * @return array<string, mixed>
     */
    public function toH(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'entitlement_feature_id' => $this->entitlementFeatureId,
            'code' => $this->code,
            'value' => $this->value,
            'value_type' => $this->valueType,
            'plan_value' => $this->planValue,
            'subscription_value' => $this->subscriptionValue,
            'name' => $this->name,
            'config' => $this->config(),
            'ordering_date' => $this->orderingDate,
            'plan_entitlement_id' => $this->planEntitlementId,
            'sub_entitlement_id' => $this->subEntitlementId,
            'plan_entitlement_value_id' => $this->planEntitlementValueId,
            'sub_entitlement_value_id' => $this->subEntitlementValueId,
        ];
    }
}
