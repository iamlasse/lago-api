<?php

declare(strict_types=1);

namespace App\Services\DataExports\Csv;

use App\Enums\FeeType;
use App\Models\DataExportPart;
use App\Models\Fee;
use App\Models\Invoice;
use App\Serializers\V1\FeeSerializer;
use App\Serializers\V1\InvoiceSerializer;
use Illuminate\Support\Carbon;

/**
 * Port of Rails' DataExports::Csv::InvoiceFees
 * (app/services/data_exports/csv/invoice_fees.rb) — one row per fee of the
 * part's invoices, with the fee's billing period resolved in the invoice
 * customer's timezone.
 */
class InvoiceFees extends BaseCsvService
{
    public const array BASE_HEADERS = [
        'invoice_lago_id',
        'invoice_number',
        'invoice_issuing_date',
        'fee_lago_id',
        'fee_item_type',
        'fee_item_code',
        'fee_item_name',
        'fee_item_description',
        'fee_item_invoice_display_name',
        'fee_item_filter_invoice_display_name',
        'fee_item_grouped_by',
        'subscription_external_id',
        'subscription_plan_code',
        'fee_from_date',
        'fee_to_date',
        'fee_amount_currency',
        'fee_units',
        'fee_precise_unit_amount',
        'fee_taxes_amount_cents',
        'fee_total_amount_cents',
    ];

    /** @return list<string> */
    protected static function buildHeaders(DataExportPart $dataExportPart): array
    {
        return self::BASE_HEADERS;
    }

    protected function collection(): iterable
    {
        // Rails: Invoice.find(ids) (fees walked per invoice below, like the
        // lazy find_each in the source).
        return Invoice::query()->with(['invoiceSubscriptions.subscription.plan'])->findMany($this->dataExportPart->object_ids ?? []);
    }

    protected function serializeItem(mixed $item, $stream): void
    {
        /** @var Invoice $invoice */
        $invoice = $item;

        $serializedInvoice = (new InvoiceSerializer($invoice))->serialize();

        // Rails: invoice_subscriptions.index_by(&:subscription_id).
        $invoiceSubscriptions = $invoice->invoiceSubscriptions
            ->keyBy('subscription_id');

        $timezone = $invoice->customer->applicableTimezone();

        $invoice->fees()
            ->with([
                'invoice',
                'subscription',
                'charge',
                'trueUpFee',
                'customer',
                'billableMetric',
                'chargeFilter.values.billableMetricFilter',
            ])
            ->get()
            ->each(function (Fee $fee) use ($stream, $serializedInvoice, $invoiceSubscriptions, $timezone): void {
                $serializedFee = (new FeeSerializer($fee))->serialize();

                $invoiceSubscription = $fee->subscription_id !== null
                    ? $invoiceSubscriptions->get($fee->subscription_id)
                    : null;

                $billingPeriod = null;

                if ($invoiceSubscription !== null || $fee->feeTypeEnum() === FeeType::AddOn) {
                    $billingPeriod = ResolveFeeBillingPeriodService::call(
                        fee: $fee,
                        invoiceSubscription: $invoiceSubscription,
                    )->raiseIfError();
                }

                [$feeFromDate, $feeToDate] = $this->feePeriodDates($fee, $billingPeriod, $timezone);

                $this->writeRow($stream, [
                    $serializedInvoice['lago_id'],
                    $serializedInvoice['number'],
                    $serializedInvoice['issuing_date'],
                    $serializedFee['lago_id'],
                    $serializedFee['item']['type'],
                    $serializedFee['item']['code'],
                    $serializedFee['item']['name'],
                    $serializedFee['item']['description'],
                    $serializedFee['item']['invoice_display_name'],
                    $serializedFee['item']['filter_invoice_display_name'],
                    $serializedFee['item']['grouped_by'],
                    $fee->subscription?->external_id,
                    $fee->subscription?->plan?->code,
                    $feeFromDate,
                    $feeToDate,
                    $serializedFee['total_amount_currency'],
                    $serializedFee['units'],
                    $serializedFee['precise_unit_amount'],
                    $serializedFee['taxes_amount_cents'],
                    $serializedFee['total_amount_cents'],
                ]);
            });
    }

    /** @return array{0: ?string, 1: ?string} Y-m-d dates in the timezone */
    protected function feePeriodDates(Fee $fee, ?object $billingPeriod, string $timezone): array
    {
        if ($fee->feeTypeEnum() === FeeType::AddOn) {
            $properties = $fee->properties ?? [];

            return [
                $this->toDate($properties['from_datetime'] ?? null),
                $this->toDate($properties['to_datetime'] ?? null),
            ];
        }

        $fromDatetime = $billingPeriod?->from_datetime ?? null;
        $toDatetime = $billingPeriod?->to_datetime ?? null;

        return [
            $this->periodDate($fromDatetime, $timezone),
            $this->periodDate($toDatetime, $timezone),
        ];
    }

    /** Rails: `period_date` — datetime&.in_time_zone(timezone)&.to_date. */
    protected function periodDate(mixed $datetime, string $timezone): ?string
    {
        if ($datetime === null) {
            return null;
        }

        return Carbon::parse($datetime, 'UTC')->setTimezone($timezone)->toDateString();
    }

    /** Rails: add-on fees take the raw property datetimes straight to_date. */
    protected function toDate(mixed $datetime): ?string
    {
        if ($datetime === null) {
            return null;
        }

        return Carbon::parse($datetime)->toDateString();
    }
}
