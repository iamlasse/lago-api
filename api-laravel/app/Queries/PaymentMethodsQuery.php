<?php

declare(strict_types=1);

namespace App\Queries;

use App\Services\BaseResult;
use App\GraphQL\Support\Page;
use App\Models\PaymentMethod;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' PaymentMethodsQuery (app/queries/payment_methods_query.rb)
 * — org-scoped payment methods with external_customer_id filter (customer
 * must not be discarded), consistent ordering (created_at desc, id asc) and
 * kaminari pagination.
 */
class PaymentMethodsQuery extends BaseService
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
        $result = static::makeResult('payment_methods');

        $methods = PaymentMethod::query()
            ->where('payment_methods.organization_id', $this->organization->id);

        $externalCustomerId = $this->filters['external_customer_id'] ?? null;

        if (is_string($externalCustomerId) && $externalCustomerId !== '') {
            $methods->whereExists(function ($sub) use ($externalCustomerId): void {
                $sub->selectRaw(1)
                    ->from('customers')
                    ->whereColumn('customers.id', 'payment_methods.customer_id')
                    ->where('customers.external_id', $externalCustomerId)
                    ->whereNull('customers.deleted_at');
            });
        }

        // Rails: with_payment_provider_customer — the connection's own methods.
        $paymentProviderCustomerId = $this->filters['payment_provider_customer_id'] ?? null;

        if (is_string($paymentProviderCustomerId) && $paymentProviderCustomerId !== '') {
            $methods->where('payment_methods.payment_provider_customer_id', $paymentProviderCustomerId);
        }

        // Rails: `with_deleted` keeps the soft-deleted methods in scope.
        if ($this->filters['with_deleted'] ?? false) {
            $methods->withTrashed();
        }

        $result->payment_methods = $this->paginate(
            $methods->latest('payment_methods.created_at')->orderBy('payment_methods.id'),
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
