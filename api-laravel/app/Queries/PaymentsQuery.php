<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Payment;
use App\Services\BaseResult;
use App\GraphQL\Support\Page;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' PaymentsQuery (app/queries/payments_query.rb).
 *
 * Ported: base scope excludes customer-less rows and invisible payables
 * (invoices must be in a visible status and belong to the organization;
 * payment request payables pass), filters invoice_id (payable or via
 * payment request join), external_customer_id, currency; consistent
 * ordering (created_at desc, id asc) and kaminari pagination.
 *
 * TODO(port): the free-text search term branches (provider_payment_id /
 * reference / invoice number / customer fields ILIKE unions + uuid branch).
 */
class PaymentsQuery extends BaseService
{
    public function __construct(
        private readonly object $organization,
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly array $filters = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payments');

        $payments = Payment::query()
            ->where('organization_id', $this->organization->id)
            ->whereNotNull('customer_id')
            ->whereNotNull('payable_id')
            ->where(function (Builder $query): void {
                // Rails: visible_payable_condition — Invoice payables must be
                // in a visible status of this organization; other payable
                // types (payment requests) pass.
                $query->where(function (Builder $q): void {
                    $q->where('payments.payable_type', '!=', 'Invoice');
                })->orWhereExists(function ($sub): void {
                    $sub->selectRaw(1)
                        ->from('invoices')
                        ->whereColumn('invoices.id', 'payments.payable_id')
                        // Rails: Invoice::VISIBLE_STATUS (draft/finalized/
                        // voided/failed/pending = 0/1/2/4/7).
                        ->whereIn('invoices.status', [0, 1, 2, 4, 7])
                        ->where('invoices.organization_id', $this->organization->id);
                });
            });

        $invoiceId = $this->filters['invoice_id'] ?? null;

        if (is_string($invoiceId) && $invoiceId !== '') {
            $payments->where(function (Builder $q) use ($invoiceId): void {
                $q->where(function (Builder $qq) use ($invoiceId): void {
                    $qq->where('payments.payable_type', 'Invoice')
                        ->where('payments.payable_id', $invoiceId);
                })->orWhereIn('payments.payable_id', function ($sub) use ($invoiceId): void {
                    $sub->select('ipr.payment_request_id')
                        ->from('invoices_payment_requests as ipr')
                        ->where('ipr.invoice_id', $invoiceId)
                        ->where('payments.payable_type', 'PaymentRequest')
                        ->whereColumn('payments.payable_id', 'ipr.payment_request_id');
                });
            });
        }

        $externalCustomerId = $this->filters['external_customer_id'] ?? null;

        if (is_string($externalCustomerId) && $externalCustomerId !== '') {
            $payments->whereExists(function ($sub) use ($externalCustomerId): void {
                $sub->selectRaw(1)
                    ->from('customers')
                    ->whereColumn('customers.id', 'payments.customer_id')
                    ->where('customers.external_id', $externalCustomerId);
            });
        }

        $currency = $this->filters['currency'] ?? null;

        if (is_string($currency) && $currency !== '') {
            $payments->where('amount_currency', $currency);
        }

        $result->payments = $this->paginate(
            $payments->latest('payments.created_at')->orderBy('payments.id'),
        );

        return $result;
    }

    private function paginate(Builder $scope): LengthAwarePaginator
    {
        [$page, $limit] = Page::normalizePageAndLimit(
            is_numeric($this->pagination['page'] ?? null) ? (int) $this->pagination['page'] : null,
            is_numeric($this->pagination['limit'] ?? null) ? (int) $this->pagination['limit'] : null,
        );

        return $scope->paginate(perPage: $limit, page: $page);
    }
}
