<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use App\Models\Quote;
use App\Models\Customer;
use App\Support\License;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\QuoteVersions\CreateService as QuoteVersionCreateService;

/**
 * Port of Rails' Quotes::CreateService (app/services/quotes/create_service.rb)
 * — opens the deal: the quote header, its first version (initialized with the
 * deal currency) and the owners, all in one transaction.
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly ?Organization $organization,
        private readonly ?Customer $customer,
        private readonly ?Subscription $subscription = null,
        private readonly array $params = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('quote');

        if (! License::premium()) {
            return $result->forbiddenFailure();
        }

        if ($this->organization === null) {
            return $result->notFoundFailure('organization');
        }

        if ($this->customer === null) {
            return $result->notFoundFailure('customer');
        }

        if ($this->subscriptionRequired() && ($this->subscription === null || $this->subscription->id === null)) {
            return $result->notFoundFailure('subscription');
        }

        if ($this->subscription !== null && $this->subscription->id !== null
            && ! $this->subscriptionBelongsToQuoteScope()) {
            return $result->notFoundFailure('subscription');
        }

        if (! $this->orderFormsEnabled($this->organization)) {
            return $result->forbiddenFailure();
        }

        if (! $this->validOwners()) {
            return $result->singleValidationFailure('invalid', 'owners');
        }

        $owners = $this->normalizeOwners($this->params['owners'] ?? null);

        return $this->rescueFailures(function () use ($result, $owners): BaseResult {
            DB::transaction(function () use ($result, $owners): void {
                $quote = new Quote;
                $quote->organization_id = $this->organization->id;
                $quote->customer_id = $this->customer->id;
                $quote->subscription_id = $this->subscription?->id;
                $quote->order_type = $this->params['order_type'];
                $quote->save();

                $this->initializeVersion($quote);

                foreach ($owners as $userId) {
                    \App\Models\QuoteOwner::query()->create([
                        'organization_id' => $quote->organization_id,
                        'quote_id' => $quote->id,
                        'user_id' => $userId,
                    ]);
                }

                // Rails: SendWebhookJob "quote.created" (on the version) and
                // Utils::ActivityLog "quote.created" (on the quote) —
                // TODO(port): webhooks / activity logs slices.

                $result->quote = $quote;
            });

            return $result;
        }, $result);
    }

    // -- Helpers ------------------------------------------------------------------

    /** Rails: subscription_required? — only amendments restate a subscription. */
    protected function subscriptionRequired(): bool
    {
        return ($this->params['order_type'] ?? null) === 'subscription_amendment';
    }

    protected function subscriptionBelongsToQuoteScope(): bool
    {
        return $this->subscription->organization_id === $this->organization->id
            && $this->subscription->customer_id === $this->customer->id;
    }

    /** Rails: valid_owners? — every owner must be an active membership user. */
    protected function validOwners(): bool
    {
        $owners = $this->normalizeOwners($this->params['owners'] ?? null);

        if ($owners === []) {
            return true;
        }

        $known = $this->organization
            ->memberships()
            ->active()
            ->whereIn('user_id', $owners)
            ->pluck('user_id')
            ->map(strval(...))
            ->all();

        return array_diff($owners, $known) === [];
    }

    /**
     * Rails: normalize_owners — scalar or list, always a flat list of unique
     * strings.
     *
     * @return list<string>
     */
    protected function normalizeOwners(mixed $owners): array
    {
        if ($owners === null || $owners === '' || $owners === []) {
            return [];
        }

        if (is_array($owners)) {
            return array_values(array_unique(array_map(strval(...), $owners)));
        }

        return [(string) $owners];
    }

    /**
     * The deal currency follows the billing object when there is one, and
     * only then the customer's own default. It stays editable on the draft
     * through QuoteVersions::UpdateService.
     */
    protected function initializeVersion(Quote $quote): void
    {
        $params = array_intersect_key($this->params, array_flip([
            'billing_items', 'content', 'billing_entity_id',
        ]));

        $params['currency'] = $this->dealCurrency();

        QuoteVersionCreateService::callBang(
            quote: $quote,
            params: $params,
        );
    }

    protected function dealCurrency(): ?string
    {
        if ($this->subscription !== null && $this->subscription->id !== null) {
            return $this->subscription->plan?->amount_currency;
        }

        $customerCurrency = ($this->customer->currency ?? '') !== ''
            ? $this->customer->currency
            : null;

        if ($customerCurrency !== null) {
            return $customerCurrency;
        }

        return $this->issuingBillingEntity()?->default_currency;
    }

    /**
     * The entity the deal is issued by, which is the one whose default
     * currency the quote should fall back to. An unknown id is left to the
     * version validator to report, so the fallback simply keeps the
     * customer's own entity here.
     */
    protected function issuingBillingEntity(): ?object
    {
        $billingEntityId = $this->params['billing_entity_id'] ?? null;

        $billingEntity = null;

        if ($billingEntityId !== null && $billingEntityId !== '') {
            $billingEntity = $this->organization
                ->billingEntities()
                ->where('id', $billingEntityId)
                ->first();
        }

        return $billingEntity ?? $this->customer->billingEntity;
    }

    protected function orderFormsEnabled(object $organization): bool
    {
        return License::premium()
            && in_array('order_forms', (array) ($organization->feature_flags ?? []), true);
    }
}
