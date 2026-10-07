<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Stripe;

use Throwable;
use App\Models\Customer;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentProvider;
use Illuminate\Support\Facades\DB;
use App\Services\PaymentProviders\FindService;
use App\Jobs\PaymentProviders\StripeRefreshWebhookJob;
use App\Jobs\PaymentProviders\StripeRegisterWebhookJob;
use App\Jobs\PaymentProviders\StripeExpirePaymentIntentsJob;

use function array_key_exists;

/**
 * Port of Rails' PaymentProviders::StripeService#create_or_update — the
 * "connect Stripe" entrypoint (api key storage). Finds the organization's
 * stripe provider by id/code or builds a new one, assigns the args (the
 * secret key is only written on creation — updating never overwrites the
 * stored key), then:
 *  - new record -> RegisterWebhookJob (Stripe webhook endpoint provisioning);
 *  - require_terms_of_service_consent flipped -> ExpirePaymentIntentsJob
 *    (outstanding checkout links embed the setting);
 *  - code changed -> update the provider customers' denormalized
 *    payment_provider_code + RefreshWebhookJob.
 *
 * Validation failures match the Rails spec envelope:
 * ["secret_key" => ["value_is_mandatory"]] on a new provider without a key,
 * ["value_already_exist"] on a duplicate code in the organization.
 */
class RegisterService extends BaseService
{
    public function __construct(
        private readonly string $organizationId,
        private readonly array $args,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('stripe_provider');

        try {
            $findResult = FindService::call(
                organizationId: $this->organizationId,
                code: $this->args['code'] ?? null,
                id: $this->args['id'] ?? null,
                paymentProviderType: 'stripe',
            );

            $provider = $findResult->success()
                ? $findResult->payment_provider
                : new PaymentProvider([
                    'organization_id' => $this->organizationId,
                    'type' => PaymentProvider::slugToType('stripe'),
                    'code' => $this->args['code'] ?? null,
                ]);

            $isNew = ! $provider->exists;
            $oldCode = $provider->code;
            $consentBefore = $provider->requireTermsOfServiceConsent();

            if ($isNew && array_key_exists('secret_key', $this->args)) {
                $provider->setSecretKey($this->args['secret_key']);
            }

            if (array_key_exists('code', $this->args)) {
                $provider->code = $this->args['code'];
            }

            if (array_key_exists('name', $this->args)) {
                $provider->name = $this->args['name'];
            }

            if (array_key_exists('success_redirect_url', $this->args)) {
                $provider->setSuccessRedirectUrl($this->args['success_redirect_url']);
            }

            if (array_key_exists('supports_3ds', $this->args)) {
                $provider->setSupports3ds($this->args['supports_3ds']);
            }

            if (array_key_exists('require_terms_of_service_consent', $this->args)) {
                $provider->setRequireTermsOfServiceConsent($this->args['require_terms_of_service_consent']);
            }

            $errors = $this->validate($provider, $isNew);

            if ($errors !== []) {
                return $result->recordValidationFailure($errors);
            }

            $provider->save();

            if ($isNew) {
                dispatch(new \App\Jobs\PaymentProviders\StripeRegisterWebhookJob($provider));
            }

            if (! $isNew && $provider->requireTermsOfServiceConsent() !== $consentBefore) {
                dispatch(new \App\Jobs\PaymentProviders\StripeExpirePaymentIntentsJob($provider));
            }

            if ($this->codeChanged($provider, $oldCode)) {
                // Rails: stripe_provider.customers.update_all(payment_provider_code:).
                // Until the webhook refresh runs, the endpoint answers 400 —
                // like Rails.
                Customer::query()
                    ->whereIn('id', DB::table('payment_provider_customers')
                        ->where('payment_provider_id', $provider->id)
                        ->whereNull('deleted_at')
                        ->select('customer_id'))
                    ->update(['payment_provider_code' => $provider->code]);

                dispatch(new \App\Jobs\PaymentProviders\StripeRefreshWebhookJob($provider));
            }

            $result->stripe_provider = $provider;

            return $result;
        } catch (Throwable) {
            return $result->singleValidationFailure('value_already_exist', 'code');
        }
    }

    /**
     * Rails: StripeProvider validations — secret_key presence (a new
     * provider must carry the key), name presence, code uniqueness among
     * kept providers of the organization, success_redirect_url format.
     *
     * @return array<string, list<string>>
     */
    private function validate(PaymentProvider $provider, bool $isNew): array
    {
        $errors = [];

        if ($isNew && ($provider->secretKey() === null || $provider->secretKey() === '')) {
            $errors['secret_key'] = ['value_is_mandatory'];
        }

        if (($provider->name ?? '') === '') {
            $errors['name'] = ['value_is_mandatory'];
        }

        $duplicate = PaymentProvider::query()
            ->where('organization_id', $provider->organization_id)
            ->where('code', $provider->code)
            ->whereNull('deleted_at')
            ->when($provider->exists, fn ($q) => $q->where('id', '!=', $provider->id))
            ->exists();

        if ($duplicate) {
            $errors['code'] = ['value_already_exist'];
        }

        $successRedirectUrl = $provider->successRedirectUrl();

        if ($successRedirectUrl !== null
            && $successRedirectUrl !== ''
            && filter_var($successRedirectUrl, FILTER_VALIDATE_URL) === false) {
            $errors['success_redirect_url'] = ['invalid_url'];
        }

        return $errors;
    }

    private function codeChanged(PaymentProvider $provider, ?string $oldCode): bool
    {
        return ! $provider->wasRecentlyCreated && $provider->code !== null && $provider->code !== $oldCode;
    }
}
