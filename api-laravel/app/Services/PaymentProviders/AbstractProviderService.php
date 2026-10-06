<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders;

use Throwable;
use App\Models\Customer;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentProvider;
use Illuminate\Support\Facades\DB;

use function array_key_exists;

/**
 * Port of Rails' PaymentProviders::BaseService
 * (app/services/payment_providers/base_service.rb) — the shared skeleton of
 * the per-provider `create_or_update` services: find-or-new by id/code, the
 * `payment_provider_code_changed?` helper driving the denormalized
 * payment_provider_code propagation, and the security-log guarantee.
 *
 * TODO(port): Utils::SecurityLog.produce("integration.created"/"updated") —
 * the security log producer is not ported yet.
 */
abstract class AbstractProviderService extends BaseService
{
    public function __construct(
        private readonly object $organization,
        private readonly array $args,
    ) {
        parent::__construct();
    }

    /** Rails: the provider type slug ("adyen", "cashfree", …). */
    abstract protected function slug(): string;

    /** Rails: `Result = BaseResult[:<slug>_provider]` — the result attribute name. */
    abstract protected function providerAttribute(): string;

    /** Rails: the provider-specific attribute assignment block. */
    abstract protected function applyAttributes(PaymentProvider $provider, array $args): void;

    /** Rails: the post-save tail (webhook jobs, customer propagation). */
    protected function afterSave(PaymentProvider $provider, array $args, bool $isNew, bool $codeChanged): void {}

    /** Port of `PaymentProviders::<Slug>Service#create_or_update`, via `#call`. */
    public function execute(): BaseResult
    {
        $result = static::makeResult($this->providerAttribute());

        try {
            $args = $this->args;

            $findResult = FindService::call(
                organizationId: $this->organization->id,
                code: $args['code'] ?? null,
                id: $args['id'] ?? null,
                paymentProviderType: $this->slug(),
            );

            $provider = $findResult->success()
                ? $findResult->payment_provider
                : new PaymentProvider([
                    'organization_id' => $this->organization->id,
                    'type' => PaymentProvider::slugToType($this->slug()),
                    'code' => $args['code'] ?? null,
                ]);

            $oldCode = $provider->code;

            $this->applyAttributes($provider, $args);

            $provider->save();

            $codeChanged = $this->paymentProviderCodeChanged($provider, $oldCode, $args);

            if ($codeChanged) {
                // Rails: provider.customers.update_all(payment_provider_code:).
                Customer::query()
                    ->whereIn('id', DB::table('payment_provider_customers')
                        ->where('payment_provider_id', $provider->id)
                        ->whereNull('deleted_at')
                        ->select('customer_id'))
                    ->update(['payment_provider_code' => $provider->code]);
            }

            $this->afterSave($provider, $args, ! $provider->wasRecentlyCreated, $codeChanged);

            $result->{$this->providerAttribute()} = $provider;

            // TODO(port): Utils::SecurityLog.produce("integration.created"/"updated").

            return $result;
        } catch (Throwable) {
            // Rails: rescue ActiveRecord::RecordInvalid -> record_validation_failure!.
            return $result->singleValidationFailure('value_already_exist', 'code');
        }
    }

    /**
     * Rails: PaymentProviders::BaseService#payment_provider_code_changed? —
     * persisted, the args carry a code, and the code actually changed.
     *
     * @param  array<string, mixed>  $args
     */
    protected function paymentProviderCodeChanged(PaymentProvider $provider, ?string $oldCode, array $args): bool
    {
        return $provider->exists
            && array_key_exists('code', $args)
            && $oldCode !== $args['code'];
    }

    /** Rails: SecretsStorable#push_to_secrets. */
    protected function pushToSecrets(PaymentProvider $provider, string $key, mixed $value): void
    {
        $secrets = $provider->secrets ?? [];
        $secrets[$key] = $value;
        $provider->secrets = $secrets;
    }
}
