<?php

declare(strict_types=1);

namespace App\Services\QuoteVersions;

use App\Models\Plan;
use App\Models\Coupon;
use App\Support\License;
use App\Models\QuoteVersion;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Validators\Currencies;
use App\Services\QuoteVersions\Validators\Validators;

/**
 * Port of Rails' QuoteVersions::UpdateService
 * (app/services/quote_versions/update_service.rb).
 *
 * Only a draft is editable. The billing items carry their own copy of the
 * deal currency, which the rendered quote reads, so every update realigns
 * the stored payload against the deal currency before the validators run.
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?QuoteVersion $quoteVersion,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('quote_version');
        $quoteVersion = $this->quoteVersion;

        if ($quoteVersion === null) {
            return $result->notFoundFailure('quote_version');
        }

        if (! $this->orderFormsEnabled($quoteVersion->organization)) {
            return $result->forbiddenFailure();
        }

        if (! $quoteVersion->isDraft()) {
            return $result->singleValidationFailure('not_editable', 'status');
        }

        $params = array_intersect_key($this->params, array_flip([
            'billing_items', 'content', 'currency', 'billing_entity_id',
        ]));

        foreach ($params as $key => $value) {
            $quoteVersion->{$key} = $value;
        }

        $currencyChanged = $quoteVersion->isDirty('currency');

        if ($currencyChanged && $this->isAmendment($quoteVersion)) {
            return $result->singleValidationFailure('not_supported_for_order_type', 'currency');
        }

        $this->realignBillingItemsCurrency($quoteVersion);

        $validator = Validators::for($result, $quoteVersion, 'update');

        if ($validator !== null && ! $validator->valid()) {
            return $result;
        }

        DB::transaction(function () use ($quoteVersion, $result): void {
            $quoteVersion->save();

            $result->quote_version = $quoteVersion;
        });

        return $result;
    }

    // -- Helpers ------------------------------------------------------------------

    /**
     * An amendment restates a subscription that is already invoicing in its
     * plan's currency, and the quote takes that currency at creation.
     * Repricing it here would switch a running subscription mid-life.
     */
    protected function isAmendment(QuoteVersion $quoteVersion): bool
    {
        return $quoteVersion->quote->order_type === 'subscription_amendment';
    }

    /**
     * The billing items carry their own copy of the currency, which the
     * rendered quote reads, so the stored payload is realigned rather than
     * resolved later from the deal.
     *
     * This runs before the structural pass, on a payload that is whatever
     * JSON the caller sent, so anything the validator would reject is left
     * exactly as it arrived for it to report. Every update realigns, not only
     * the ones changing the currency. Realigning is idempotent.
     */
    protected function realignBillingItemsCurrency(QuoteVersion $quoteVersion): void
    {
        // Nothing coherent to realign against: a blank currency is still
        // editable at this scope, and an invalid one is about to fail
        // validation, so stamping either across the payload would only spread
        // a value the deal does not have.
        $currency = $quoteVersion->currency;

        if ($currency === null || ! Currencies::valid($currency)) {
            return;
        }

        $items = $quoteVersion->billing_items;

        if (! is_array($items) || $items === []) {
            return;
        }

        $items = $this->deepStringifyKeys($items);

        $realigned = $items;

        if (array_key_exists('plans', $items)) {
            $realigned['plans'] = $this->realignPlans($quoteVersion, $items['plans']);
        }

        if (array_key_exists('coupons', $items)) {
            $realigned['coupons'] = $this->realignCoupons($quoteVersion, $items['coupons']);
        }

        if (array_key_exists('walletCredits', $items)) {
            $realigned['walletCredits'] = $this->realignWalletCredits($quoteVersion, $items['walletCredits']);
        }

        $quoteVersion->billing_items = $realigned;
    }

    protected function realignPlans(QuoteVersion $quoteVersion, mixed $plans): mixed
    {
        if (! is_array($plans)) {
            return $plans;
        }

        $byId = $this->catalogPlansById($quoteVersion);

        return array_map(function (mixed $item) use ($byId, $quoteVersion): mixed {
            if (! is_array($item)) {
                return $item;
            }

            $plan = $byId[$item['id'] ?? null] ?? null;

            if ($plan === null) {
                return $item;
            }

            return $this->withCurrencyOverride($quoteVersion, $item, catalogCurrency: $plan->amount_currency);
        }, $plans);
    }

    protected function realignCoupons(QuoteVersion $quoteVersion, mixed $coupons): mixed
    {
        if (! is_array($coupons)) {
            return $coupons;
        }

        $byId = $this->catalogCouponsById($quoteVersion);

        return array_map(function (mixed $item) use ($byId, $quoteVersion): mixed {
            if (! is_array($item)) {
                return $item;
            }

            $coupon = $byId[$item['id'] ?? null] ?? null;

            if ($coupon === null) {
                return $item;
            }

            // A percentage coupon is never priced in a currency, so it reads
            // as already matching and any override it carries is stale.
            $catalogCurrency = $coupon->coupon_type?->label() === 'fixed_amount'
                ? $coupon->amount_currency
                : $quoteVersion->currency;

            return $this->withCurrencyOverride($quoteVersion, $item, catalogCurrency: $catalogCurrency);
        }, $coupons);
    }

    /**
     * The override states the deal currency only while the catalog record is
     * priced in another one. Dropping it once the two agree matters as much
     * as setting it: an override left behind states a currency the deal no
     * longer uses, and fails validation exactly as a missing one did.
     *
     * Emptying the overrides leaves an empty object rather than removing the
     * key: everything reading the payload reaches for `overrides.name`, and a
     * key that disappeared takes the whole page down with it. Only the
     * currency key is ever touched; the figure is not converted.
     */
    protected function withCurrencyOverride(QuoteVersion $quoteVersion, array $item, mixed $catalogCurrency): array
    {
        $submitted = $item['overrides'] ?? null;

        // Null is a shape the schema accepts, so it realigns like an absent
        // key. Anything else that is not an object does not, and coercing it
        // into one here would quietly answer the question the structural pass
        // is about to ask.
        if ($submitted !== null && ! is_array($submitted)) {
            return $item;
        }

        $overrides = $submitted ?? [];
        $stated = $overrides['amountCurrency'] ?? null;

        // Same for the value it states: a currency that is not one is kept as
        // submitted, rather than replaced by a valid payload the caller never
        // sent.
        if ($stated !== null && ! Currencies::valid($stated)) {
            return $item;
        }

        $realigned = ($catalogCurrency === $quoteVersion->currency)
            ? array_diff_key($overrides, ['amountCurrency' => true])
            : array_merge($overrides, ['amountCurrency' => $quoteVersion->currency]);

        // An item that never carried the key, on a deal the catalog already
        // matches, is left exactly as it arrived rather than gaining an empty
        // object.
        if ($realigned === $overrides) {
            return $item;
        }

        $item['overrides'] = $realigned;

        return $item;
    }

    protected function realignWalletCredits(QuoteVersion $quoteVersion, mixed $walletCredits): mixed
    {
        if (! is_array($walletCredits)) {
            return $walletCredits;
        }

        return array_map(function (mixed $item) use ($quoteVersion): mixed {
            if (! is_array($item)) {
                return $item;
            }

            $payload = $item['payload'] ?? null;

            if (! is_array($payload)) {
                return $item;
            }

            // Only a credit stating a real currency has anything stale to
            // realign. One stating none keeps stating none, because execution
            // falls back to the deal's own, and one stating something that is
            // not a currency keeps that too, for the validator to report.
            if (! Currencies::valid($payload['currency'] ?? null)) {
                return $item;
            }

            $item['payload'] = array_merge($payload, ['currency' => $quoteVersion->currency]);

            return $item;
        }, $walletCredits);
    }

    /** Rails: catalog_plans_by_id — with_discarded. */
    protected function catalogPlansById(QuoteVersion $quoteVersion): array
    {
        $ids = $this->billingItemIds($quoteVersion, 'plans');

        if ($ids === []) {
            return [];
        }

        return Plan::query()
            ->withTrashed()
            ->where('organization_id', $quoteVersion->organization_id)
            ->whereIn('plans.id', $ids)
            ->get()
            ->keyBy('id')
            ->all();
    }

    /** Rails: catalog_coupons_by_id — with_discarded. */
    protected function catalogCouponsById(QuoteVersion $quoteVersion): array
    {
        $ids = $this->billingItemIds($quoteVersion, 'coupons');

        if ($ids === []) {
            return [];
        }

        return Coupon::query()
            ->withTrashed()
            ->where('organization_id', $quoteVersion->organization_id)
            ->whereIn('coupons.id', $ids)
            ->get()
            ->keyBy('id')
            ->all();
    }

    /** @return list<string> */
    protected function billingItemIds(QuoteVersion $quoteVersion, string $key): array
    {
        $items = $this->billingItems($quoteVersion)[$key] ?? null;

        if (! is_array($items)) {
            return [];
        }

        $ids = [];

        foreach ($items as $item) {
            if (is_array($item) && isset($item['id']) && is_scalar($item['id'])) {
                $ids[] = (string) $item['id'];
            }
        }

        return $ids;
    }

    /**
     * The structural pass rejects a payload that is not an object, but it
     * runs after this.
     *
     * @return array<string, mixed>
     */
    protected function billingItems(QuoteVersion $quoteVersion): array
    {
        $items = $quoteVersion->billing_items;

        return is_array($items) ? $this->deepStringifyKeys($items) : [];
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return array<string, mixed>
     */
    protected function deepStringifyKeys(array $items): array
    {
        $stringified = [];

        foreach ($items as $key => $value) {
            $stringified[(string) $key] = is_array($value)
                ? $this->deepStringifyKeys($value)
                : $value;
        }

        return $stringified;
    }

    /** Rails: OrderForms::Premium#order_forms_enabled?. */
    protected function orderFormsEnabled(object $organization): bool
    {
        return License::premium()
            && in_array('order_forms', (array) ($organization->feature_flags ?? []), true);
    }
}
