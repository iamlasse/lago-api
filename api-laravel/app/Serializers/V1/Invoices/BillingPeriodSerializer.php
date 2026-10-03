<?php

declare(strict_types=1);

namespace App\Serializers\V1\Invoices;

use App\Models\InvoiceSubscription;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::Invoices::BillingPeriodSerializer
 * (app/serializers/v1/invoices/billing_period_serializer.rb) — one entry per
 * billed subscription on the invoice (invoice_subscriptions rows).
 */
class BillingPeriodSerializer extends ModelSerializer
{
    use FormatsDatetime;

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        /** @var InvoiceSubscription $model */
        $model = $this->model;

        return [
            'lago_subscription_id' => $model->subscription_id,
            'external_subscription_id' => $model->subscription?->external_id,
            'lago_plan_id' => $model->subscription?->plan_id,
            'subscription_from_datetime' => $this->serializeDatetime($model->from_datetime),
            'subscription_to_datetime' => $this->serializeDatetime($model->to_datetime),
            'charges_from_datetime' => $this->serializeDatetime($model->charges_from_datetime),
            'charges_to_datetime' => $this->serializeDatetime($model->charges_to_datetime),
            'fixed_charges_from_datetime' => $this->serializeDatetime($model->fixed_charges_from_datetime),
            'fixed_charges_to_datetime' => $this->serializeDatetime($model->fixed_charges_to_datetime),
            'invoicing_reason' => $model->invoicingReasonName(),
        ];
    }
}
