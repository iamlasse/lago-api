<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\Tax;

/**
 * Port of Rails' V1::TaxWithBillingEntitiesSerializer
 * (app/serializers/v1/tax_with_billing_entities_serializer.rb) — the tax
 * shape with the applied_to_organization flag recomputed against the
 * default billing entity and the attached billing entity codes.
 */
class TaxWithBillingEntitiesSerializer extends TaxSerializer
{
    public function serialize(): array
    {
        return [
            ...parent::serialize(),
            'applied_to_organization' => $this->appliedToOrganization(),
            'applied_to_billing_entities_codes' => $this->billingEntitiesCodes(),
        ];
    }

    /**
     * Rails: applied_to_organization? — true when the tax is attached to
     * the options' default_billing_entity; false when no default entity is
     * given.
     */
    private function appliedToOrganization(): bool
    {
        $defaultBillingEntity = $this->options['default_billing_entity'] ?? null;

        if ($defaultBillingEntity === null) {
            return false;
        }

        /** @var Tax $model */
        $model = $this->model;

        foreach ($model->billingEntities() as $billingEntity) {
            if ($billingEntity->id === $defaultBillingEntity->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rails: model.billing_entities.map(&:code).sort — byte-wise sort.
     *
     * @return list<string>
     */
    private function billingEntitiesCodes(): array
    {
        /** @var Tax $model */
        $model = $this->model;

        $codes = $model->billingEntities()->map(fn ($entity): string => (string) $entity->code)->all();

        sort($codes, SORT_STRING);

        return array_values($codes);
    }
}
