<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Enums\InvoiceType;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::InvoiceSerializer (app/serializers/v1/invoice_serializer.rb).
 *
 * TODO(port) includes, waiting on models not yet in the Laravel port:
 * metadata (Metadata::InvoiceMetadata), error_details, applied_usage_thresholds,
 * applied_invoice_custom_sections, payments. file_url / xml_url go through
 * the ActiveStorage port (App\Support\ActiveStorage).
 */
class InvoiceSerializer extends ModelSerializer
{
    use FormatsDatetime;

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        $model = $this->model;

        $payload = [
            'lago_id' => $model->id,
            'billing_entity_code' => $model->billingEntity?->code,
            'sequential_id' => $model->sequential_id,
            'number' => $model->number,
            'purchase_order_number' => $model->purchase_order_number,
            'issuing_date' => $this->serializeDateIso($model->issuing_date),
            'payment_due_date' => $this->serializeDateIso($model->payment_due_date),
            'net_payment_term' => $model->net_payment_term,
            'invoice_type' => $model->typeEnum()?->label(),
            'status' => $model->statusEnum()?->label(),
            'payment_status' => $model->paymentStatusEnum()?->label(),
            'payment_dispute_lost_at' => $this->serializeDatetime($model->payment_dispute_lost_at),
            'payment_overdue' => $model->payment_overdue,
            'currency' => $model->currency,
            'fees_amount_cents' => $model->fees_amount_cents,
            'taxes_amount_cents' => $model->taxes_amount_cents,
            'progressive_billing_credit_amount_cents' => $model->progressive_billing_credit_amount_cents,
            'coupons_amount_cents' => $model->coupons_amount_cents,
            'credit_notes_amount_cents' => $model->credit_notes_amount_cents,
            'sub_total_excluding_taxes_amount_cents' => $model->sub_total_excluding_taxes_amount_cents,
            'sub_total_including_taxes_amount_cents' => $model->sub_total_including_taxes_amount_cents,
            'total_amount_cents' => $model->total_amount_cents,
            'total_due_amount_cents' => $model->totalDueAmountCents(),
            'total_paid_amount_cents' => $model->total_paid_amount_cents,
            'total_offsetted_credit_note_amount_cents' => 0, // TODO(port): offset_amount_cents (credit notes).
            'prepaid_credit_amount_cents' => $model->prepaid_credit_amount_cents,
            'prepaid_granted_credit_amount_cents' => $model->prepaid_granted_credit_amount_cents,
            'prepaid_purchased_credit_amount_cents' => $model->prepaid_purchased_credit_amount_cents,
            'file_url' => $model->fileUrl(),
            'xml_url' => $model->xmlUrl(),
            'web_url' => $model->webUrl(),
            'version_number' => $model->version_number,
            'self_billed' => $model->self_billed,
            'created_at' => $this->serializeDatetime($model->created_at),
            'updated_at' => $this->serializeDatetime($model->updated_at),
            'voided_at' => $this->serializeDatetime($model->voided_at),
        ];

        if ($this->include('customer')) {
            $payload = [...$payload, ...$this->customer()];
        }

        if ($this->include('subscriptions')) {
            $payload = [...$payload, ...$this->subscriptions()];
        }

        if ($this->include('billing_periods')) {
            $payload = [...$payload, ...$this->billingPeriods()];
        }

        if ($this->include('fees')) {
            $payload = [...$payload, ...$this->fees()];
        }

        if ($this->include('credits')) {
            $payload = [...$payload, ...$this->credits()];
        }

        if ($this->include('metadata')) {
            $payload['metadata'] = [];
        }

        if ($this->include('applied_taxes')) {
            $payload = [...$payload, ...$this->appliedTaxes()];
        }

        // Rails: payload.merge!(applied_usage_thresholds) if model.progressive_billing?
        // (an invoice_type enum predicate — AppliedUsageThreshold unported,
        // empty collection).
        if ($model->typeEnum() === InvoiceType::ProgressiveBilling) {
            $payload['applied_usage_thresholds'] = [];
        }

        if ($this->include('error_details')) {
            // TODO(port): ErrorDetail models — empty collection.
            $payload['error_details'] = [];
        }

        if ($this->include('applied_invoice_custom_sections')) {
            // TODO(port): InvoiceCustomSection models — empty collection.
            $payload['applied_invoice_custom_sections'] = [];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function customer(): array
    {
        return [
            'customer' => (new CustomerSerializer(
                $this->model->customer,
                ['includes' => $this->include('integration_customers') ? ['integration_customers'] : []],
            ))->serialize(),
        ];
    }

    /** @return array<string, mixed> */
    private function subscriptions(): array
    {
        $subscriptions = $this->model->invoiceSubscriptions()
            ->get()
            ->sortByDesc(fn ($is) => $is->to_datetime ?? $is->created_at)
            ->map(fn ($is) => $is->subscription)
            ->values();

        return (new CollectionSerializer(
            $subscriptions,
            SubscriptionSerializer::class,
            ['collection_name' => 'subscriptions'],
        ))->serialize();
    }

    /**
     * Port of Rails' billing_periods: the invoice's invoice_subscriptions,
     * ordered by the subscription's invoice name
     * (COALESCE(subscriptions.name, plans.invoice_display_name, plans.name)).
     */
    /** @return array<string, mixed> */
    private function billingPeriods(): array
    {
        $periods = $this->model->invoiceSubscriptions()
            ->select('invoice_subscriptions.*')
            ->join('subscriptions', 'subscriptions.id', '=', 'invoice_subscriptions.subscription_id')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->orderByRaw('COALESCE(subscriptions.name, plans.invoice_display_name, plans.name) ASC')
            ->get();

        return (new CollectionSerializer(
            $periods,
            Invoices\BillingPeriodSerializer::class,
            ['collection_name' => 'billing_periods'],
        ))->serialize();
    }

    /** @return array<string, mixed> */
    private function fees(): array
    {
        return (new CollectionSerializer(
            $this->model->fees,
            FeeSerializer::class,
            ['collection_name' => 'fees'],
        ))->serialize();
    }

    /** @return array<string, mixed> */
    private function credits(): array
    {
        return (new CollectionSerializer(
            $this->model->credits,
            CreditSerializer::class,
            ['collection_name' => 'credits'],
        ))->serialize();
    }

    /** @return array<string, mixed> */
    private function appliedTaxes(): array
    {
        return (new CollectionSerializer(
            $this->model->appliedTaxes,
            Invoices\AppliedTaxSerializer::class,
            ['collection_name' => 'applied_taxes'],
        ))->serialize();
    }

    /** Rails `iso8601` on a date — "2025-06-05" (Date, not Time, semantics). */
    private function serializeDateIso(mixed $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        if (is_string($date)) {
            $date = \Carbon\CarbonImmutable::parse($date, 'UTC');
        }

        return \Carbon\CarbonImmutable::instance($date)->format('Y-m-d');
    }
}
