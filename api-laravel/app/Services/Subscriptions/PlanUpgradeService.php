<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Models\Plan;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Enums\SubscriptionStatus;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Subscriptions::PlanUpgradeService
 * (app/services/subscriptions/plan_upgrade_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): plan_overrides — Plans::OverrideService is deferred with the
 *   premium override work; the new subscription always carries the given plan.
 * - TODO(port): Subscriptions::ActivationRules::ApplyService.
 * - TODO(port): BillingEntities::ResolveService override when the params carry
 *   billing_entity_id/code.
 * - TODO(port): payment_method resolution (PaymentMethod model).
 */
class PlanUpgradeService extends BaseService
{
    protected Subscription $currentSubscription;

    protected Plan $plan;

    /** @var array<string, mixed> */
    protected array $params;

    protected string $name;

    public function __construct(Subscription $currentSubscription, Plan $plan, array $params)
    {
        parent::__construct();

        $this->currentSubscription = $currentSubscription;
        $this->plan = $plan;
        $this->params = $params;
        $this->name = mb_trim((string) ($params['name'] ?? ''));
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('subscription');

        DB::transaction(function () use ($result): void {
            if ($this->currentSubscription->startingInTheFuture()) {
                // TODO(port): apply_activation_rules when params[:activation_rules].
                $this->updatePendingSubscription();

                $result->subscription = $this->currentSubscription;

                return;
            }

            $newSubscription = $this->newSubscriptionWithOverrides();

            if ($this->pendingSubscription()) {
                $this->cancelPendingSubscription();
            }

            $newSubscription->status = SubscriptionStatus::Pending->value;
            $newSubscription->save();

            // TODO(port): apply_activation_rules when params[:activation_rules].present?.

            ActivateService::callBang(subscription: $newSubscription);

            $result->subscription = $newSubscription;
        });

        return $result;
    }

    // -- Helpers ---------------------------------------------------------------------

    protected function newSubscriptionWithOverrides(): Subscription
    {
        // TODO(port): resolved_entity = resolve_billing_entity(...) — the new
        // subscription inherits the current billing entity meanwhile.
        $resolvedEntityId = null;

        $newSubscription = new Subscription([
            'organization_id' => $this->currentSubscription->customer->organization_id,
            'customer_id' => $this->currentSubscription->customer->id,
            // TODO(port): params.key?(:plan_overrides) ? override_plan : plan
            'plan_id' => $this->plan->id,
            'name' => $this->name,
            'external_id' => $this->currentSubscription->external_id,
            'previous_subscription_id' => $this->currentSubscription->id,
            'subscription_at' => $this->currentSubscription->subscription_at,
            'billing_time' => $this->currentSubscription->billing_time,
            'ending_at' => array_key_exists('ending_at', $this->params)
                ? $this->params['ending_at']
                : $this->currentSubscription->ending_at,
            'consolidate_invoice' => array_key_exists('consolidate_invoice', $this->params)
                ? filter_var($this->params['consolidate_invoice'], FILTER_VALIDATE_BOOLEAN)
                : $this->currentSubscription->consolidate_invoice,
            'purchase_order_number' => array_key_exists('purchase_order_number', $this->params)
                ? $this->params['purchase_order_number']
                : $this->currentSubscription->purchase_order_number,
        ]);

        $newSubscription->billing_entity_id = $resolvedEntityId ?? $this->currentSubscription->billing_entity_id;

        $paymentMethod = $this->params['payment_method'] ?? null;
        if (is_array($paymentMethod)) {
            if (array_key_exists('payment_method_type', $paymentMethod)) {
                $newSubscription->payment_method_type = $paymentMethod['payment_method_type'];
            }

            if (array_key_exists('payment_method_id', $paymentMethod)) {
                $newSubscription->payment_method_id = $paymentMethod['payment_method_id'];
            }
        }

        return $newSubscription;
    }

    protected function updatePendingSubscription(): void
    {
        $this->currentSubscription->plan_id = $this->plan->id;

        if ($this->name !== '') {
            $this->currentSubscription->name = $this->name;
        }

        $this->currentSubscription->save();
    }

    protected function cancelPendingSubscription(): void
    {
        $next = $this->currentSubscription->nextSubscription();

        if ($next === null) {
            return;
        }

        $next->markAsCanceled();
        $next->save();
    }

    protected function pendingSubscription(): bool
    {
        $next = $this->currentSubscription->nextSubscription();

        return $next !== null && $next->pending();
    }
}
