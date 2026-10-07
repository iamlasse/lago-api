<?php

declare(strict_types=1);

namespace App\Queries;

use App\Services\BaseResult;
use App\GraphQL\Support\Page;
use App\Services\BaseService;
use App\Models\PaymentReceipt;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' PaymentReceiptsQuery (app/queries/payment_receipts_query.rb)
 * — organization scope, the invoice_id filter (the receipt's own payment
 * payable, or a payment of one of the invoice's payment requests), kaminari
 * pagination and the consistent ordering (created_at desc, id asc).
 */
class PaymentReceiptsQuery extends BaseService
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
        $result = static::makeResult('payment_receipts');

        $receipts = PaymentReceipt::query()
            ->where('payment_receipts.organization_id', $this->organization->id);

        $invoiceId = $this->filters['invoice_id'] ?? null;

        if (is_string($invoiceId) && $invoiceId !== '') {
            // Rails: INNER JOIN payments + LEFT JOIN invoices / payment_requests
            // (+ invoices_payment_requests), matching
            // "invoices.id = :invoice_id OR invoices_payment_requests.invoice_id = :invoice_id".
            $receipts->whereExists(function ($sub) use ($invoiceId): void {
                $sub->selectRaw(1)
                    ->from('payments')
                    ->whereColumn('payments.id', 'payment_receipts.payment_id')
                    ->where(function ($q) use ($invoiceId): void {
                        $q->whereExists(function ($invoice) use ($invoiceId): void {
                            $invoice->selectRaw(1)
                                ->from('invoices')
                                ->whereColumn('invoices.id', 'payments.payable_id')
                                ->where('payments.payable_type', 'Invoice')
                                ->where('invoices.id', $invoiceId);
                        })->orWhereExists(function ($request) use ($invoiceId): void {
                            $request->selectRaw(1)
                                ->from('payment_requests')
                                ->whereColumn('payment_requests.id', 'payments.payable_id')
                                ->where('payments.payable_type', 'PaymentRequest')
                                ->where('payment_requests.organization_id', $this->organization->id)
                                ->whereExists(function ($join) use ($invoiceId): void {
                                    $join->selectRaw(1)
                                        ->from('invoices_payment_requests as ipr')
                                        ->whereColumn('ipr.payment_request_id', 'payment_requests.id')
                                        ->where('ipr.invoice_id', $invoiceId);
                                });
                        });
                    });
            });
        }

        $externalCustomerId = $this->filters['external_customer_id'] ?? null;

        if (is_string($externalCustomerId) && $externalCustomerId !== '') {
            $receipts->whereExists(function ($sub) use ($externalCustomerId): void {
                $sub->selectRaw(1)
                    ->from('payments')
                    ->whereColumn('payments.id', 'payment_receipts.payment_id')
                    ->whereExists(function ($cust) use ($externalCustomerId): void {
                        $cust->selectRaw(1)
                            ->from('customers')
                            ->whereColumn('customers.id', 'payments.customer_id')
                            ->where('customers.external_id', $externalCustomerId);
                    });
            });
        }

        $result->payment_receipts = $this->paginate(
            $receipts->latest('payment_receipts.created_at')->orderBy('payment_receipts.id'),
        );

        return $result;
    }

    private function paginate(\Illuminate\Database\Eloquent\Builder $scope): LengthAwarePaginator
    {
        [$page, $limit] = Page::normalizePageAndLimit(
            is_numeric($this->pagination['page'] ?? null) ? (int) $this->pagination['page'] : null,
            is_numeric($this->pagination['limit'] ?? null) ? (int) $this->pagination['limit'] : null,
        );

        return $scope->paginate(perPage: $limit, page: $page);
    }
}
