<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Plan;
use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Http\Request;
use App\Enums\SubscriptionStatus;
use Illuminate\Http\JsonResponse;
use App\Queries\SubscriptionsQuery;
use App\Services\Failures\FailedResult;
use App\Exceptions\Api\NotFoundException;
use Illuminate\Database\Eloquent\Builder;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Services\Subscriptions\CreateService;
use App\Services\Subscriptions\UpdateService;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\SubscriptionSerializer;
use App\Services\BillingEntities\ResolveService;
use App\Services\Subscriptions\TerminateService;
use App\Exceptions\Api\ParameterMissingException;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Port of Rails' Api::V1::SubscriptionsController (app/controllers/api/v1/
 * subscriptions_controller.rb).
 *
 * NOTE: a subscription is never destroyed — DELETE terminates it (the
 * terminate semantics live in Subscriptions\TerminateService).
 *
 * Not ported (out of scope for this slice — dependencies do not exist yet):
 * - the payment pre-authorization flow (PaymentProviders::Stripe::Payments::
 *   AuthorizeService + CancelPaymentAuthorizationJob) — the `authorization`
 *   param gates are enforced (feature_not_available / stripe_required) and
 *   the Stripe call itself is deferred at the marked hook;
 * - the nested subresources (lifetime_usages, alerts, entitlements,
 *   charges, fixed_charges) — no ported controllers/services.
 */
class SubscriptionsController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'subscription';

    public function create(Request $request): JsonResponse
    {
        $billingEntityResult = ResolveService::call(
            organization: $this->currentOrganization(),
            billingEntityCode: $request->input('subscription.billing_entity_code'),
        );

        if (! $billingEntityResult->success()) {
            $this->renderErrorResponse($billingEntityResult);
        }

        $billingEntity = $billingEntityResult->billing_entity;

        $createParams = $this->createParams($request);

        $customer = Customer::query()->firstOrNew([
            'external_id' => mb_trim((string) ($createParams['external_customer_id'] ?? '')),
            'organization_id' => $this->currentOrganization()->id,
        ]);

        if ($customer->billing_entity_id === null) {
            $customer->billing_entity_id = $billingEntity->id;
        }

        // Rails renders these envelopes inline (not via the error-result flow).
        if ($request->input('authorization') !== null) {
            if (! $this->betaPaymentAuthorizationEnabled()) {
                return new JsonResponse([
                    'status' => 403,
                    'error' => 'Forbidden',
                    'code' => 'feature_not_available',
                    'message' => 'Payment authorization (beta_payment_authorization) is not available for this organization',
                ], 403);
            }

            if ($customer->payment_provider !== 'stripe') {
                return new JsonResponse([
                    'status' => 422,
                    'error' => 'Unprocessable Entity',
                    'code' => 'stripe_required',
                    'message' => 'Only Stripe is supported for authorization',
                ], 422);
            }

            // TODO(port): PaymentProviders::Stripe::Payments::AuthorizeService —
            // the subscription is created without a pre-authorization payload
            // in the response meanwhile.
        }

        $plan = Plan::query()
            ->parents()
            ->where('code', $createParams['plan_code'] ?? null)
            ->where('organization_id', $this->currentOrganization()->id)
            ->first();

        if ($plan === null) {
            throw new NotFoundException('plan');
        }

        $result = CreateService::call(
            customer: $customer,
            plan: $plan,
            params: $createParams,
        );

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).

            $response = ['subscription' => (new SubscriptionSerializer(
                $result->subscription,
                ['includes' => ['plan', 'entitlements', 'applicable_usage_thresholds', 'applied_invoice_custom_sections']],
            ))->serialize()];

            return $this->renderSerializerJson((string) json_encode($response, JSON_UNESCAPED_SLASHES));
        }

        $this->renderErrorResponse($result);
    }

    /**
     * NOTE: We can't destroy a subscription, it will terminate it.
     */
    public function terminate(Request $request): JsonResponse
    {
        $query = $this->currentOrganization()
            ->subscriptions()
            ->where('external_id', $request->route('external_id'));

        $subscription = $this->subscriptionsMatchingStatus($query, $request)->first();

        try {
            $result = TerminateService::call(
                subscription: $subscription,
                onTerminationCreditNote: $this->scalarParam($request, 'on_termination_credit_note'),
                onTerminationInvoice: $this->scalarParam($request, 'on_termination_invoice'),
            );
        } catch (FailedResult $failure) {
            // Rails: a nested `call!` raising FailedResult (e.g. the
            // on_termination update failing validation) is rendered by the
            // controller-level rescue_from — same envelope.
            $this->renderErrorResponse($failure->result);
        }

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).

            return $this->renderSubscription(
                $result->subscription,
                ['plan', 'applied_invoice_custom_sections'],
            );
        }

        $this->renderErrorResponse($result);
    }

    public function update(Request $request): JsonResponse
    {
        $query = $this->currentOrganization()
            ->subscriptions()
            ->where('external_id', $request->route('external_id'))
            ->orderByDesc('subscription_at');

        $subscription = ($query->count() > 1
            ? $this->subscriptionsMatchingStatus($query, $request)
            : $query
        )->first();

        $result = UpdateService::call(
            subscription: $subscription,
            params: $this->updateParams($request),
        );

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).

            return $this->renderSubscription(
                $result->subscription,
                ['plan', 'applicable_usage_thresholds', 'applied_invoice_custom_sections'],
            );
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $status = SubscriptionStatus::fromOption($request->input('status') ?? 'active');

        if ($status === null) {
            throw new NotFoundException('subscription');
        }

        $subscription = $this->currentOrganization()
            ->subscriptions()
            ->orderByRaw('terminated_at desc nulls first')
            ->orderByDesc('started_at')
            ->where('external_id', $request->route('external_id'))
            ->where('status', $status)
            ->first();

        if ($subscription === null) {
            throw new NotFoundException('subscription');
        }

        return $this->renderSubscription(
            $subscription,
            ['plan', 'entitlements', 'applicable_usage_thresholds', 'applied_invoice_custom_sections'],
        );
    }

    public function index(Request $request): JsonResponse
    {
        $billingEntityIds = null;

        $billingEntityCodes = $request->query('billing_entity_codes');

        if ($billingEntityCodes !== null && $billingEntityCodes !== '' && $billingEntityCodes !== []) {
            $codes = is_array($billingEntityCodes) ? $billingEntityCodes : [$billingEntityCodes];

            $billingEntities = $this->currentOrganization()
                ->allBillingEntities()
                ->whereIn('code', $codes)
                ->get();

            if ($billingEntities->count() !== count($codes)) {
                throw new NotFoundException('billing_entity');
            }

            $billingEntityIds = $billingEntities->pluck('id')->all();
        }

        // Port of the SubscriptionIndex concern's
        // `params.permit(:plan_code, :overriden, :overridden, :currency,
        // :external_id, status: [])` filter — a scalar sent where the array
        // `status` is declared is dropped entirely (defaulting to active).
        $filters = [];

        foreach (['plan_code', 'overriden', 'overridden', 'currency', 'external_id'] as $key) {
            $value = $request->query($key);

            if ($value !== null && ! is_array($value)) {
                $filters[$key] = $value;
            }
        }

        $status = $request->query('status');
        $filters['status'] = is_array($status)
            ? array_values(array_filter($status, fn ($entry): bool => is_scalar($entry)))
            : [];

        if ($filters['status'] === []) {
            $filters['status'] = ['active'];
        }

        $externalCustomerId = $request->query('external_customer_id');

        if ($externalCustomerId !== null && ! is_array($externalCustomerId)) {
            $filters['external_customer_id'] = $externalCustomerId;
        }

        $filters['billing_entity_ids'] = $billingEntityIds;

        $result = SubscriptionsQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            filters: $filters,
        );

        if ($result->success()) {
            return $this->renderSerializerJson(
                (new CollectionSerializer(
                    $result->subscriptions,
                    SubscriptionSerializer::class,
                    [
                        'collection_name' => 'subscriptions',
                        'meta' => $this->paginationMetadata($result->subscriptions),
                    ],
                ))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers ---------------------------------------------------------------------

    /**
     * Port of `subscriptions_matching_status`: the status param picks which
     * subscription of an external_id family is targeted (default active).
     */
    private function subscriptionsMatchingStatus(
        HasMany $query,
        Request $request,
    ): Builder {
        $matching = match ($request->input('status')) {
            'pending' => $query->pending(),
            'incomplete' => $query->incomplete(),
            default => $query->active(),
        };

        return $matching instanceof HasMany
            ? $matching->getQuery()
            : $matching;
    }

    /**
     * Port of `params.permit(:on_termination_credit_note, ...)` — only a
     * scalar (or absent) value survives.
     */
    private function scalarParam(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Port of Organization#beta_payment_authorization_enabled? (the
     * PREMIUM_INTEGRATIONS `define_method` block): License.premium? &&
     * premium_integrations.include?("beta_payment_authorization").
     */
    private function betaPaymentAuthorizationEnabled(): bool
    {
        if (config('lago.license') === null || config('lago.license') === '') {
            return false;
        }

        return in_array(
            'beta_payment_authorization',
            (array) ($this->currentOrganization()->premium_integrations ?? []),
            true,
        );
    }

    /**
     * Port of `render_subscription`: every single-subscription response
     * preloads nothing extra (the serializer lazy-loads the ported relations
     * itself) and carries the given includes.
     *
     * @param  list<string>  $includes
     */
    private function renderSubscription(Subscription $subscription, array $includes): JsonResponse
    {
        return $this->renderSerializerJson((new SubscriptionSerializer(
            $subscription,
            [
                'root_name' => 'subscription',
                'includes' => $includes,
            ],
        ))->toJson());
    }

    /**
     * Port of `params.require(:subscription).permit(...)` — the create
     * contract, verbatim (Rails' permitted params).
     *
     * @return array<string, mixed>
     */
    private function createParams(Request $request): array
    {
        /** @var mixed $subscription */
        $subscription = $this->requireParam($request, 'subscription');

        if (! is_array($subscription)) {
            throw new ParameterMissingException('subscription');
        }

        return $this->permitParams($subscription, [
            'external_customer_id',
            'plan_code',
            'billing_entity_code',
            'billing_entity_id',
            'name',
            'external_id',
            'billing_time',
            'subscription_at',
            'ending_at',
            'progressive_billing_disabled',
            'consolidate_invoice',
            'purchase_order_number',
            'invoice_custom_section' => [
                'skip_invoice_custom_sections',
                'invoice_custom_section_codes' => [],
            ],
            'activation_rules' => [['type', 'timeout_hours']],
            'payment_method' => [
                'payment_method_type',
                'payment_method_id',
            ],
            'connections' => [
                'payment' => ['behavior', 'code'],
                'tax' => ['behavior', 'code'],
                'accounting' => ['behavior', 'code'],
                'crm' => ['behavior', 'code'],
            ],
            'usage_thresholds' => [['amount_cents', 'threshold_display_name', 'recurring']],
            'plan_overrides' => $this->planOverridesParams(),
        ]);
    }

    /**
     * Port of `update_params` — the update contract, verbatim (Rails'
     * permitted params; note there is no plan_code — plan changes go
     * through plan_overrides).
     *
     * @return array<string, mixed>
     */
    private function updateParams(Request $request): array
    {
        /** @var mixed $subscription */
        $subscription = $this->requireParam($request, 'subscription');

        if (! is_array($subscription)) {
            throw new ParameterMissingException('subscription');
        }

        return $this->permitParams($subscription, [
            'name',
            'subscription_at',
            'ending_at',
            'on_termination_credit_note',
            'on_termination_invoice',
            'progressive_billing_disabled',
            'billing_entity_id',
            'billing_entity_code',
            'consolidate_invoice',
            'purchase_order_number',
            'activation_rules' => [['type', 'timeout_hours']],
            'invoice_custom_section' => [
                'skip_invoice_custom_sections',
                'invoice_custom_section_codes' => [],
            ],
            'payment_method' => [
                'payment_method_type',
                'payment_method_id',
            ],
            'connections' => [
                'payment' => ['behavior', 'code'],
                'tax' => ['behavior', 'code'],
                'accounting' => ['behavior', 'code'],
                'crm' => ['behavior', 'code'],
            ],
            'usage_thresholds' => [['amount_cents', 'threshold_display_name', 'recurring']],
            'plan_overrides' => $this->planOverridesParams(),
        ]);
    }

    /**
     * Port of `plan_overrides` — the nested plan_overrides schema, verbatim.
     *
     * @return array<string, mixed>
     */
    private function planOverridesParams(): array
    {
        return [
            'amount_cents',
            'amount_currency',
            'description',
            'name',
            'invoice_display_name',
            'trial_period',
            'tax_codes' => [],
            'minimum_commitment' => [
                'invoice_display_name',
                'amount_cents',
                'tax_codes' => [],
            ],
            'charges' => [[
                'id',
                'billable_metric_id',
                'code',
                'min_amount_cents',
                'invoice_display_name',
                'charge_model',
                'properties' => '*',
                'filters' => [[
                    'invoice_display_name',
                    'properties' => '*',
                    'values' => '*',
                ]],
                'tax_codes' => [],
                'applied_pricing_unit' => [
                    'code',
                    'conversion_rate',
                ],
            ]],
            'fixed_charges' => [[
                'id',
                'invoice_display_name',
                'units',
                'apply_units_immediately',
                'properties' => '*',
                'tax_codes' => [],
            ]],
            'usage_thresholds' => [[
                'id',
                'threshold_display_name',
                'amount_cents',
                'recurring',
            ]],
        ];
    }
}
