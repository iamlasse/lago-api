<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Throwable;
use App\Models\Invoice;
use App\Enums\InvoiceStatus;
use Illuminate\Http\Request;
use App\Services\Invoices\Query;
use Illuminate\Http\JsonResponse;
use App\Jobs\Invoices\GeneratePdfJob;
use App\Services\Invoices\VoidService;
use App\Services\Invoices\RetryService;
use App\Services\Invoices\DeleteService;
use App\Services\Invoices\UpdateService;
use App\Exceptions\Api\NotFoundException;
use App\Serializers\V1\InvoiceSerializer;
use App\Serializers\V1\PaymentProviders\InvoicePaymentSerializer;
use App\Services\Invoices\Payments\GeneratePaymentUrlService;
use App\Exceptions\Api\ForbiddenException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Services\Invoices\LoseDisputeService;
use App\Serializers\Base\CollectionSerializer;
use App\Services\Invoices\CreateOneOffService;
use App\Services\Invoices\RefreshDraftService;
use App\Exceptions\Api\MethodNotAllowedException;
use App\Services\Invoices\RefreshDraftAndFinalizeService;

/**
 * Port of Rails' Api::V1::InvoicesController
 * (app/controllers/api/v1/invoices_controller.rb) — invoices are keyed by
 * their uuid id (Rails: resources :invoices with no param constraint... the
 * uuid format never contains a dot, so the default constraint applies).
 *
 * Not ported (dependencies out of scope): retry_payment
 * (Invoices::Payments::RetryService) and sync_salesforce_id — see
 * routes/api.php. resend_email (Emails::ResendService) and payment_url
 * (GeneratePaymentUrlService) are wired; payment_url's happy path lives
 * with the PSP slice (PaymentIntents::FetchService). Preview answers the
 * premium feature_unavailable envelope.
 */
class InvoicesController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'invoice';

    public function create(Request $request): JsonResponse
    {
        $params = $this->createParams($request);

        $result = CreateOneOffService::call(
            customer: $this->customer($params),
            currency: $params['currency'] ?? null,
            fees: $params['fees'] ?? [],
            timestamp: now()->getTimestamp(),
            skipPsp: (bool) ($params['skip_psp'] ?? false),
            invoiceCustomSection: $params['invoice_custom_section'] ?? [],
            paymentMethodParams: $params['payment_method'] ?? null,
            billingEntityCode: $params['billing_entity_code'] ?? null,
            purchaseOrderNumber: $params['purchase_order_number'] ?? null,
        );

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable).

            return $this->renderInvoice($result->invoice);
        }

        $this->renderErrorResponse($result);
    }

    public function update(Request $request): JsonResponse
    {
        $invoice = $this->visibleInvoice($request);

        if ($invoice?->isVoided()) {
            throw new MethodNotAllowedException('update_on_voided_invoice');
        }

        $result = new UpdateService(
            invoice: $invoice,
            params: $this->updateParams($request),
            webhookNotification: true,
        );

        $result = $result->execute();

        if ($result->success()) {
            return $this->renderInvoice($result->invoice);
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $invoice = $this->visibleInvoice($request);

        if ($invoice === null) {
            throw new NotFoundException('invoice');
        }

        return $this->renderInvoice($invoice);
    }

    public function index(Request $request): JsonResponse
    {
        $billingEntityIds = null;

        $billingEntityCodes = $this->arrayParam($request, 'billing_entity_codes');

        if ($billingEntityCodes !== null) {
            $billingEntities = $this->currentOrganization()
                ->allBillingEntities()
                ->whereIn('code', $billingEntityCodes)
                ->get();

            if ($billingEntities->count() !== count($billingEntityCodes)) {
                throw new NotFoundException('billing_entity');
            }

            $billingEntityIds = $billingEntities->pluck('id')->all();
        }

        $result = Query::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page') !== null ? (int) $request->query('page') : null,
                'limit' => $request->query('per_page') !== null && $request->query('per_page') !== ''
                    ? (int) $request->query('per_page')
                    : self::PER_PAGE,
            ],
            searchTerm: $request->query('search_term'),
            filters: [
                'amount_from' => $request->query('amount_from'),
                'amount_to' => $request->query('amount_to'),
                'billing_entity_ids' => $billingEntityIds,
                'currency' => $request->query('currency'),
                'customer_external_id' => $request->query('external_customer_id')
                    ?? $request->query('customer_external_id'),
                'invoice_type' => $this->listParam($request, 'invoice_type'),
                'issuing_date_from' => $this->dateParam($request, 'issuing_date_from'),
                'issuing_date_to' => $this->dateParam($request, 'issuing_date_to'),
                // TODO(port): the metadata filter (Metadata::InvoiceMetadata unported).
                'partially_paid' => $request->query('partially_paid'),
                'payment_dispute_lost' => $request->query('payment_dispute_lost'),
                'payment_overdue' => $request->query('payment_overdue'),
                // Rails wraps bare params with Array(...) before the query
                // object intersects them — mirror that here.
                'payment_status' => $this->listParam($request, 'payment_status')
                    ?? $this->listParam($request, 'payment_statuses'),
                'purchase_order_number' => $request->query('purchase_order_number'),
                'settlements' => $this->listParam($request, 'settlements'),
                'self_billed' => $request->query('self_billed'),
                'status' => $this->listParam($request, 'status')
                    ?? $this->listParam($request, 'statuses'),
            ],
        );

        if ($result->success()) {
            // Eager-load mirrors Rails' preload (fees for the serializer,
            // customer.billing_entity for the code).
            $invoices = $result->invoices->getCollection();

            return $this->renderSerializerJson(
                (new CollectionSerializer(
                    $invoices,
                    InvoiceSerializer::class,
                    [
                        'collection_name' => 'invoices',
                        'meta' => $this->paginationMetadata(
                            $result->invoices,
                            key: 'invoices',
                            organizationId: (string) $this->currentOrganization()->id,
                            params: $request->query(),
                        ),
                        'includes' => [
                            'customer',
                            'integration_customers',
                            'metadata',
                            'applied_taxes',
                        ],
                    ],
                ))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    public function downloadPdf(Request $request): JsonResponse
    {
        $invoice = $this->finalizedInvoice($request);

        if ($invoice === null) {
            throw new NotFoundException('invoice');
        }

        if ($invoice->hasFile()) {
            return $this->renderInvoice($invoice);
        }

        // Rails: Invoices::GeneratePdfJob.perform_later(invoice) then head(:ok).
        GeneratePdfJob::dispatch($invoice);

        return response()->json(null, 200);
    }

    public function downloadXml(Request $request): JsonResponse
    {
        $invoice = $this->finalizedInvoice($request);

        if ($invoice === null) {
            throw new NotFoundException('invoice');
        }

        if ($invoice->hasXmlFile()) {
            return $this->renderInvoice($invoice);
        }

        // Rails: Invoices::GenerateXmlJob.perform_later(invoice) then
        // head(:ok). TODO(port): the XML renderer (EInvoices UBL) is not
        // ported, so nothing is enqueued yet — the endpoint keeps answering
        // the ok-without-file shape Rails answers while the file is missing.

        return response()->json(null, 200);
    }

    public function refresh(Request $request): JsonResponse
    {
        $invoice = $this->visibleInvoice($request);

        if ($invoice === null) {
            throw new NotFoundException('invoice');
        }

        $result = RefreshDraftService::call(invoice: $invoice);

        if ($result->success()) {
            return $this->renderInvoice($result->invoice);
        }

        $this->renderErrorResponse($result);
    }

    public function finalize(Request $request): JsonResponse
    {
        $invoice = $this->currentOrganization()
            ->invoices()
            ->where('invoices.status', InvoiceStatus::Draft->value)
            ->where('invoices.id', $request->route('id'))
            ->first();

        if ($invoice === null) {
            throw new NotFoundException('invoice');
        }

        $result = RefreshDraftAndFinalizeService::call(invoice: $invoice);

        if ($result->success()) {
            return $this->renderInvoice($result->invoice);
        }

        $this->renderErrorResponse($result);
    }

    public function void(Request $request): JsonResponse
    {
        $invoice = $this->visibleInvoice($request);

        $result = VoidService::call(
            invoice: $invoice,
            params: $request->only(['generate_credit_note', 'refund_amount', 'credit_amount']),
        );

        if ($result->success()) {
            return $this->renderInvoice($result->invoice);
        }

        $this->renderErrorResponse($result);
    }

    public function destroy(Request $request): JsonResponse
    {
        $invoice = $this->visibleInvoice($request);

        $result = DeleteService::call(invoice: $invoice);

        if ($result->success()) {
            return $this->renderInvoice($result->invoice);
        }

        $this->renderErrorResponse($result);
    }

    public function loseDispute(Request $request): JsonResponse
    {
        $invoice = $this->visibleInvoice($request);

        $result = LoseDisputeService::call(
            invoice: $invoice,
            paymentDisputeLostAt: now()->toDateTimeString(),
        );

        if ($result->success()) {
            return $this->renderInvoice($result->invoice);
        }

        $this->renderErrorResponse($result);
    }

    public function retry(Request $request): JsonResponse
    {
        $invoice = $this->visibleInvoice($request);

        if ($invoice === null) {
            throw new NotFoundException('invoice');
        }

        $result = RetryService::call(invoice: $invoice);

        if ($result->success()) {
            return $this->renderInvoice($result->invoice);
        }

        $this->renderErrorResponse($result);
    }

    /**
     * Rails: `preview`, behind PremiumFeatureOnly — before_action
     * `forbidden_error(code: "feature_unavailable") unless License.premium?`.
     * The OSS port answers the same envelope without evaluating the license.
     */
    public function preview(): never
    {
        throw new ForbiddenException('feature_unavailable');
    }

    /**
     * Rails: `resend_email` — Emails::ResendService; success answers
     * head(:ok) (no body).
     */
    public function resendEmail(Request $request): JsonResponse
    {
        $invoice = $this->visibleInvoice($request);

        $result = \App\Services\Emails\ResendService::call(
            resource: $invoice,
            to: $this->listParam($request, 'to'),
            cc: $this->listParam($request, 'cc'),
            bcc: $this->listParam($request, 'bcc'),
        );

        if ($result->success()) {
            return response()->json(null, 200);
        }

        $this->renderErrorResponse($result);
    }

    /**
     * Rails: `payment_url` —
     * Invoices::Payments::GeneratePaymentUrlService; success renders the
     * invoice_payment_details serializer.
     */
    public function paymentUrl(Request $request): JsonResponse
    {
        $invoice = $this->visibleInvoice($request);

        $invoiceWithCustomer = $invoice?->loadMissing('customer');

        $result = GeneratePaymentUrlService::call(invoice: $invoiceWithCustomer);

        if ($result->success()) {
            return $this->renderSerializerJson((new InvoicePaymentSerializer(
                $invoiceWithCustomer,
                [
                    'root_name' => 'invoice_payment_details',
                    'payment_url' => $result->payment_url,
                ],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers ---------------------------------------------------------------------

    /**
     * Port of `render_invoice` — the serializer includes list. Note the
     * unported includes (integration_customers, metadata, error_details,
     * applied_invoice_custom_sections) are passed through; the serializer
     * ignores what it cannot render yet.
     */
    private function renderInvoice(Invoice $invoice): JsonResponse
    {
        return $this->renderSerializerJson((new InvoiceSerializer($invoice, [
            'root_name' => 'invoice',
            'includes' => [
                'customer',
                'integration_customers',
                'billing_periods',
                'subscriptions',
                'fees',
                'credits',
                'metadata',
                'applied_taxes',
                'error_details',
                'applied_invoice_custom_sections',
            ],
        ]))->toJson());
    }

    /** Rails: current_organization.invoices.visible.find_by(id: params[:id]). */
    private function visibleInvoice(Request $request): ?Invoice
    {
        return $this->currentOrganization()
            ->invoices()
            ->visible()
            ->where('invoices.id', $request->route('id'))
            ->first();
    }

    /** Rails: current_organization.invoices.finalized.find_by(id:). */
    private function finalizedInvoice(Request $request): ?Invoice
    {
        return $this->currentOrganization()
            ->invoices()
            ->where('invoices.status', InvoiceStatus::Finalized->value)
            ->where('invoices.id', $request->route('id'))
            ->first();
    }

    /** Rails: Customer.find_by(external_id:, organization_id:). */
    private function customer(array $params): ?object
    {
        if (($params['external_customer_id'] ?? null) === null) {
            return null;
        }

        return \App\Models\Customer::query()
            ->where('external_id', $params['external_customer_id'])
            ->where('organization_id', $this->currentOrganization()->id)
            ->first();
    }

    /**
     * Port of `create_params` — params.require(:invoice).permit(...) with
     * the nested fees / invoice_custom_section / payment_method schemas.
     *
     * @return array<string, mixed>
     */
    private function createParams(Request $request): array
    {
        /** @var mixed $raw */
        $raw = $this->requireParam($request, 'invoice');

        if (! is_array($raw)) {
            throw new \App\Exceptions\Api\ParameterMissingException('invoice');
        }

        return $this->permitParams($raw, [
            'external_customer_id',
            'currency',
            'skip_psp',
            'billing_entity_code',
            'purchase_order_number',
            // Rails `fees: [...permits...]` — an array of permitted hashes
            // (the double-array form of permitParams).
            'fees' => [[
                'add_on_code',
                'invoice_display_name',
                'unit_amount_cents',
                'units',
                'description',
                'from_datetime',
                'to_datetime',
                'tax_codes' => [],
            ]],
            'invoice_custom_section' => [
                'skip_invoice_custom_sections',
                'invoice_custom_section_codes' => [],
            ],
            'payment_method' => [
                'payment_method_type',
                'payment_method_id',
            ],
        ]);
    }

    /**
     * Port of `update_params` — permit(:payment_status, metadata: [:id, :key, :value]).
     *
     * @return array<string, mixed>
     */
    private function updateParams(Request $request): array
    {
        /** @var mixed $raw */
        $raw = $this->requireParam($request, 'invoice');

        if (! is_array($raw)) {
            throw new \App\Exceptions\Api\ParameterMissingException('invoice');
        }

        return $this->permitParams($raw, [
            'payment_status',
            // Rails `metadata: [:id, :key, :value]` — an array of hashes.
            'metadata' => [[
                'id',
                'key',
                'value',
            ]],
        ]);
    }

    /**
     * Rails `valid_date?` + Date.iso8601 — nil unless the param is a real
     * Y-m-d date.
     */
    private function dateParam(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return $value;
    }

    /** A single query param normalized to a list (Rails' blank-aware arrays). */
    private function arrayParam(Request $request, string $key): ?array
    {
        return $this->listParam($request, $key);
    }

    /** Rails `Array(param)` — scalars wrap into a list, blanks stay absent. */
    private function listParam(Request $request, string $key): ?array
    {
        $value = $request->query($key);

        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return is_array($value) ? array_values(array_map(strval(...), $value)) : [(string) $value];
    }
}
