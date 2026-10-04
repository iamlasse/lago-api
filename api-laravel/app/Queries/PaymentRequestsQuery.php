<?php

declare(strict_types=1);

namespace App\Queries;

use App\Services\BaseResult;
use App\GraphQL\Support\Page;
use App\Services\BaseService;
use App\Models\PaymentRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' PaymentRequestsQuery (app/queries/payment_requests_query.rb)
 * — filters: billing_entity_ids (via applied invoices), external_customer_id,
 * payment_status, currency; consistent ordering (created_at desc, id asc)
 * and kaminari pagination.
 */
class PaymentRequestsQuery extends BaseService
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
        $result = static::makeResult('payment_requests');

        $requests = PaymentRequest::query()
            ->where('organization_id', $this->organization->id);

        $billingEntityIds = $this->filters['billing_entity_ids'] ?? [];

        if ($billingEntityIds !== []) {
            $requests->whereIn('id', function ($sub) use ($billingEntityIds): void {
                $sub->select('ipr.payment_request_id')
                    ->from('invoices_payment_requests as ipr')
                    ->join('invoices', 'invoices.id', '=', 'ipr.invoice_id')
                    ->whereIn('invoices.billing_entity_id', $billingEntityIds);
            });
        }

        $externalCustomerId = $this->filters['external_customer_id'] ?? null;

        if (is_string($externalCustomerId) && $externalCustomerId !== '') {
            $requests->whereExists(function ($sub) use ($externalCustomerId): void {
                $sub->selectRaw(1)
                    ->from('customers')
                    ->whereColumn('customers.id', 'payment_requests.customer_id')
                    ->where('customers.external_id', $externalCustomerId);
            });
        }

        $paymentStatus = $this->filters['payment_status'] ?? null;

        if (is_string($paymentStatus) && $paymentStatus !== '') {
            $requests->where('payment_status', array_search($paymentStatus, PaymentRequest::PAYMENT_STATUSES, true));
        }

        $currency = $this->filters['currency'] ?? null;

        if (is_string($currency) && $currency !== '') {
            $requests->where('amount_currency', $currency);
        }

        $result->payment_requests = $this->paginate(
            $requests->latest('payment_requests.created_at')->orderBy('payment_requests.id'),
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
