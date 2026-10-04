<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Throwable;
use App\Models\Invoice;
use App\Models\CreditNote;
use Illuminate\Http\Request;
use App\Queries\CreditNotesQuery;
use Illuminate\Http\JsonResponse;
use App\Exceptions\Api\NotFoundException;
use App\Services\CreditNotes\VoidService;
use App\Http\Controllers\Api\ApiController;
use App\Services\CreditNotes\CreateService;
use App\Services\CreditNotes\UpdateService;
use App\Serializers\V1\CreditNoteSerializer;
use App\Http\Controllers\Concerns\Pagination;
use App\Services\CreditNotes\EstimateService;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\CreditNotes\EstimateSerializer;

/**
 * Port of Rails' Api::V1::CreditNotesController
 * (app/controllers/api/v1/credit_notes_controller.rb) — credit notes are
 * keyed by their uuid id (Rails: resources :credit_notes with the default
 * param constraint; uuids contain no dots).
 *
 * Not ported (dependencies do not exist yet):
 * - resend_email (Emails::ResendService) — see routes/api.php;
 * - the metadata subresource (Metadata::ItemMetadata is not attached to
 *   credit notes yet — CreateService/UpdateService carry TODO(port)s);
 * - download_pdf / download_xml enqueue the Gotenberg/XML pipelines — the
 *   routes answer head(:ok) without enqueueing, like the invoices ones.
 */
class CreditNotesController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'credit_note';

    public function create(Request $request): JsonResponse
    {
        $params = $this->inputParams($request);

        $result = CreateService::call(
            invoice: $this->visibleInvoice($params['invoice_id'] ?? null),
            items: $params['items'] ?? null,
            reason: $params['reason'] ?? null,
            description: $params['description'] ?? null,
            creditAmountCents: $params['credit_amount_cents'] ?? 0,
            refundAmountCents: $params['refund_amount_cents'] ?? 0,
            offsetAmountCents: $params['offset_amount_cents'] ?? 0,
            metadata: $params['metadata'] ?? null,
        );

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable).

            return $this->renderCreditNote($result->credit_note);
        }

        $this->renderErrorResponse($result);
    }

    public function update(Request $request): JsonResponse
    {
        $creditNote = $this->notDeletedCreditNote($request);

        if ($creditNote === null) {
            throw new NotFoundException('credit_note');
        }

        $result = new UpdateService(
            creditNote: $creditNote,
            params: $this->updateParams($request),
            partialMetadata: true,
        );

        $result = $result->execute();

        if ($result->success()) {
            return $this->renderCreditNote($result->credit_note);
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $creditNote = $this->finalizedCreditNote($request);

        if ($creditNote === null) {
            throw new NotFoundException('credit_note');
        }

        return $this->renderCreditNote($creditNote);
    }

    public function downloadPdf(Request $request): JsonResponse
    {
        $creditNote = $this->finalizedCreditNote($request);

        if ($creditNote === null) {
            throw new NotFoundException('credit_note');
        }

        if ($creditNote->file !== null && $creditNote->file !== '') {
            return $this->renderCreditNote($creditNote, includes: false);
        }

        // TODO(port): CreditNotes::GeneratePdfJob (Gotenberg) then head(:ok) —
        // the PDF pipeline is a later milestone; answer head(:ok) without
        // enqueueing (mirrors the invoices download endpoints).

        return response()->json(null, 200);
    }

    public function downloadXml(Request $request): JsonResponse
    {
        $creditNote = $this->finalizedCreditNote($request);

        if ($creditNote === null) {
            throw new NotFoundException('credit_note');
        }

        if ($creditNote->xml_file !== null && $creditNote->xml_file !== '') {
            return $this->renderCreditNote($creditNote, includes: false);
        }

        // TODO(port): CreditNotes::GenerateXmlJob then head(:ok) — the XML
        // pipeline is a later milestone.

        return response()->json(null, 200);
    }

    public function void(Request $request): JsonResponse
    {
        $creditNote = $this->notDeletedCreditNote($request);

        if ($creditNote === null) {
            throw new NotFoundException('credit_note');
        }

        $result = VoidService::call(creditNote: $creditNote);

        if ($result->success()) {
            return $this->renderCreditNote($result->credit_note);
        }

        $this->renderErrorResponse($result);
    }

    public function estimate(Request $request): JsonResponse
    {
        $params = $this->estimateParams($request);

        $result = EstimateService::call(
            invoice: $this->visibleInvoice($params['invoice_id'] ?? null),
            items: $params['items'] ?? null,
        );

        if ($result->success()) {
            return $this->renderSerializerJson(
                (new EstimateSerializer($result->credit_note, [
                    'root_name' => 'estimated_credit_note',
                ]))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    /**
     * Rails: `credit_note_index(external_customer_id: params[:external_customer_id])`
     * — the CreditNoteIndex concern's shared index behind the top-level and
     * customers-nested controllers.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->creditNoteIndex($request, $request->query('external_customer_id'));
    }

    /** The customers-nested controller funnels here with the route customer. */
    protected function creditNoteIndex(Request $request, ?string $externalCustomerId): JsonResponse
    {
        $billingEntityIds = null;

        $billingEntityCodes = $request->query('billing_entity_codes');

        if (is_array($billingEntityCodes) && $billingEntityCodes !== []) {
            $billingEntities = $this->currentOrganization()
                ->allBillingEntities()
                ->whereIn('code', $billingEntityCodes)
                ->get();

            if ($billingEntities->count() !== count($billingEntityCodes)) {
                throw new NotFoundException('billing_entity');
            }

            $billingEntityIds = $billingEntities->pluck('id')->all();
        }

        $result = CreditNotesQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page') !== null ? (int) $request->query('page') : null,
                'limit' => $request->query('per_page') !== null && $request->query('per_page') !== ''
                    ? (int) $request->query('per_page')
                    : self::PER_PAGE,
            ],
            searchTerm: $request->query('search_term'),
            filters: [
                'customer_external_id' => $externalCustomerId ?? $request->query('external_customer_id'),
                'amount_from' => $request->query('amount_from'),
                'amount_to' => $request->query('amount_to'),
                'billing_entity_ids' => $billingEntityIds,
                'credit_status' => $this->listParam($request, 'credit_status'),
                'currency' => $request->query('currency'),
                'invoice_number' => $request->query('invoice_number'),
                'purchase_order_number' => $request->query('purchase_order_number'),
                'issuing_date_from' => $this->dateParam($request, 'issuing_date_from'),
                'issuing_date_to' => $this->dateParam($request, 'issuing_date_to'),
                'reason' => $this->listParam($request, 'reason'),
                'refund_status' => $this->listParam($request, 'refund_status'),
                'self_billed' => $request->query('self_billed'),
                'types' => $this->listParam($request, 'types'),
            ],
        );

        if ($result->success()) {
            return $this->renderSerializerJson(
                (new CollectionSerializer(
                    $result->credit_notes->getCollection(),
                    CreditNoteSerializer::class,
                    [
                        'collection_name' => 'credit_notes',
                        'meta' => $this->paginationMetadata(
                            $result->credit_notes,
                            key: 'credit_notes',
                            organizationId: (string) $this->currentOrganization()->id,
                            params: $request->query(),
                        ),
                        'includes' => [
                            'items',
                            'applied_taxes',
                            'error_details',
                            ['customer' => ['integration_customers']],
                        ],
                    ],
                ))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers ---------------------------------------------------------------------

    /**
     * Port of `include_in_serializer` — the serializer includes list. Note
     * the unported includes (integration_customers, error_details) are
     * passed through; the serializer ignores what it cannot render yet.
     *
     * @return list<mixed>
     */
    private function serializerIncludes(): array
    {
        return [
            'items',
            'applied_taxes',
            'error_details',
            ['customer' => ['integration_customers']],
        ];
    }

    private function renderCreditNote(CreditNote $creditNote, bool $includes = true): JsonResponse
    {
        return $this->renderSerializerJson((new CreditNoteSerializer($creditNote, [
            'root_name' => 'credit_note',
            'includes' => $includes ? $this->serializerIncludes() : [],
        ]))->toJson());
    }

    /** Rails: current_organization.invoices.visible.find_by(id:). */
    private function visibleInvoice(?string $invoiceId): ?Invoice
    {
        if ($invoiceId === null || $invoiceId === '') {
            return null;
        }

        return $this->currentOrganization()
            ->invoices()
            ->visible()
            ->where('invoices.id', $invoiceId)
            ->first();
    }

    /**
     * Rails: `current_organization.credit_notes.finalized.find_by(id:)` —
     * the show/download lookup.
     */
    private function finalizedCreditNote(Request $request): ?CreditNote
    {
        return CreditNote::query()
            ->where('organization_id', $this->currentOrganization()->id)
            ->finalized()
            ->where('credit_notes.id', $request->route('id'))
            ->first();
    }

    /**
     * Rails: `current_organization.credit_notes.not_deleted.find_by(id:)` —
     * the update/void lookup (discard's not_deleted has no port: the
     * credit_notes table carries no deleted_at column).
     */
    private function notDeletedCreditNote(Request $request): ?CreditNote
    {
        return CreditNote::query()
            ->where('organization_id', $this->currentOrganization()->id)
            ->where('credit_notes.id', $request->route('id'))
            ->first();
    }

    /**
     * Port of `input_params` — params.require(:credit_note).permit(...).
     *
     * @return array<string, mixed>
     */
    private function inputParams(Request $request): array
    {
        /** @var mixed $raw */
        $raw = $this->requireParam($request, 'credit_note');

        if (! is_array($raw)) {
            throw new \App\Exceptions\Api\ParameterMissingException('credit_note');
        }

        return $this->permitParams($raw, [
            'invoice_id',
            'reason',
            'description',
            'credit_amount_cents',
            'refund_amount_cents',
            'offset_amount_cents',
            // Rails `metadata: {}` — a hash with unconstrained keys.
            'metadata' => '*',
            // Rails `items: [:fee_id, :amount_cents]` — an array of hashes.
            'items' => [
                ['fee_id', 'amount_cents'],
            ],
        ]);
    }

    /**
     * Port of `update_params` — permit(:refund_status, metadata: {}).
     *
     * @return array<string, mixed>
     */
    private function updateParams(Request $request): array
    {
        /** @var mixed $raw */
        $raw = $this->requireParam($request, 'credit_note');

        if (! is_array($raw)) {
            throw new \App\Exceptions\Api\ParameterMissingException('credit_note');
        }

        return $this->permitParams($raw, [
            'refund_status',
            'metadata' => '*',
        ]);
    }

    /**
     * Port of `estimate_params` — require(:credit_note).permit(:invoice_id, items: [...]).
     *
     * @return array<string, mixed>
     */
    private function estimateParams(Request $request): array
    {
        /** @var mixed $raw */
        $raw = $this->requireParam($request, 'credit_note');

        if (! is_array($raw)) {
            throw new \App\Exceptions\Api\ParameterMissingException('credit_note');
        }

        return $this->permitParams($raw, [
            'invoice_id',
            // Rails `items: [:fee_id, :amount_cents]` — an array of hashes.
            'items' => [
                ['fee_id', 'amount_cents'],
            ],
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
