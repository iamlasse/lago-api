<?php

declare(strict_types=1);

namespace App\Models\Subscription\ActivationRule;

use App\Models\Subscription\ActivationRule;
use Illuminate\Database\Eloquent\Attributes\Table;

/**
 * Port of the Rails Subscription::ActivationRule::Payment STI subclass
 * (app/models/subscription/activation_rule/payment.rb) — the activation
 * rule gating a subscription on the successful payment of its gating
 * (pay-in-advance) invoice.
 */
#[Table(name: 'subscription_activation_rules')]
class Payment extends ActivationRule
{
    public static function stiType(): ?string
    {
        return 'payment';
    }

    public static function stiName(): string
    {
        return 'payment';
    }

    /** Rails: `#applicable?`. */
    public function applicable(): bool
    {
        $subscription = $this->subscription;

        if ($subscription === null) {
            return false;
        }

        if ($subscription->plan->pay_in_advance && ! $subscription->inTrialPeriod()) {
            return true;
        }

        return $this->hasPayInAdvanceFixedCharges();
    }

    private function hasPayInAdvanceFixedCharges(): bool
    {
        $subscription = $this->subscription;
        assert($subscription !== null);

        return $subscription->fixedCharges()
            ->where('fixed_charges.pay_in_advance', true)
            ->exists();
    }
}
