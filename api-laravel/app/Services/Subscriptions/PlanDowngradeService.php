<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\BillingEntities\ResolveService;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Subscriptions::PlanDowngradeService
 * (app/services/subscriptions/plan_downgrade_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): plan_overrides — Plans::OverrideService is deferred with the
 *   premium override work; the new subscription always carries the given plan.
 * - TODO(port): Subscriptions::ActivationRules::ApplyService.
 * - TODO(port): InvoiceCustomSections::AttachToResourceService.
 * - TODO(port): BillingObjectConnections::AttachToResourceService.
 * - TODO(port): SendWebhookJob "subscription.updated" + ActivityLog.
 * - TODO(port): Hubspot sync.
 */
class PlanDowngradeService extends BaseService
{
    protected Customer $customer;

    protected Subscription $currentSubscription;

    protected Plan $plan;

    /** @var array<string, mixed> */
    protected array $params;

    protected string $name;

    public function __construct(Customer $customer, Subscription $currentSubscription, Plan $plan, array $params)
    {
        parent::__construct();

        $this->customer = $customer;
        $this->currentSubscription = $currentSubscription;
        $this->plan = $plan;
        $this->params = $params;
        $this->name = trim((string) ($params['name'] ?? ''));
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

            if ($this->pendingSubscription()) {
                $this->cancelPendingSubscription();
            }

            // NOTE: When downgrading a subscription, we keep the current one
            // active until the next billing day. The new subscription will
            // become active at this date.
            $newSubscription = $this->currentSubscription->nextSubscriptions()->make([
                'organization_id' => $this->customer->organization_id,
                'customer_id' => $this->customer->id,
                // TODO(port): params.key?(:plan_overrides) ? override_plan : plan
                'plan_id' => $this->plan->id,
                'name' => $this->name,
                'external_id' => $this->currentSubscription->external_id,
                'subscription_at' => $this->currentSubscription->subscription_at,
                'status' => SubscriptionStatus::Pending->value,
                'billing_time' => $this->currentSubscription->billing_time,
                'ending_at' => array_key_exists('ending_at', $this->params)
                    ? $this->params['ending_at']
                    : $this->currentSubscription->ending_at,
                'progressive_billing_disabled' => $this->params['progressive_billing_disabled'] ?? false,
                'consolidate_invoice' => array_key_exists('consolidate_invoice', $this->params)
                    ? filter_var($this->params['consolidate_invoice'], FILTER_VALIDATE_BOOLEAN)
                    : $this->currentSubscription->consolidate_invoice,
                'purchase_order_number' => array_key_exists('purchase_order_number', $this->params)
                    ? $this->params['purchase_order_number']
                    : $this->currentSubscription->purchase_order_number,
            ]);

            $newSubscription->billing_entity_id = $this->currentSubscription->billing_entity_id;

            $errors = $newSubscription->validateAttributes();
            if ($errors !== []) {
                $result->recordValidationFailure($errors)->raiseIfError();
            }

            $newSubscription->save();

            // TODO(port): apply_activation_rules when params[:activation_rules].present?.

            if (! blank($this->params['billing_entity_id'] ?? null) || ! blank($this->params['billing_entity_code'] ?? null)) {
                $overrideEntity = ResolveService::call(
                    organization: $this->currentSubscription->organization,
                    billingEntityCode: $this->params['billing_entity_code'] ?? null,
                )->raiseIfError()->billing_entity;

                if ($overrideEntity !== null) {
                    $newSubscription->billing_entity_id = $overrideEntity->id;
                    $newSubscription->save();
                }
            }

            $paymentMethod = $this->params['payment_method'] ?? null;
            if (is_array($paymentMethod)) {
                if (array_key_exists('payment_method_type', $paymentMethod)) {
                    $newSubscription->payment_method_type = $paymentMethod['payment_method_type'];
                }

                if (array_key_exists('payment_method_id', $paymentMethod)) {
                    $newSubscription->payment_method_id = $paymentMethod['payment_method_id'];
                }

                $newSubscription->save();
            }

            // TODO(port): InvoiceCustomSections::AttachToResourceService.
            // TODO(port): BillingObjectConnections::AttachToResourceService.
            // TODO(port): SendWebhookJob "subscription.updated" on the current
            // subscription + ActivityLog + Hubspot.

            $result->subscription = $this->currentSubscription;
        });

        return $result;
    }

    // -- Helpers ---------------------------------------------------------------------

    protected function updatePendingSubscription(): void
    {
        $this->currentSubscription->plan_id = $this->plan->id;

        if ($this->name !== '') {
            $this->currentSubscription->name = $this->name;
        }

        if (! blank($this->params['billing_entity_id'] ?? null) || ! blank($this->params['billing_entity_code'] ?? null)) {
            $overrideEntity = ResolveService::call(
                organization: $this->currentSubscription->organization,
                billingEntityCode: $this->params['billing_entity_code'] ?? null,
            )->raiseIfError()->billing_entity;

            if ($overrideEntity !== null) {
                $this->currentSubscription->billing_entity_id = $overrideEntity->id;
            }
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
