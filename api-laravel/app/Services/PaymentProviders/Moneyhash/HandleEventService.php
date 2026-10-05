<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Moneyhash;

use Throwable;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Failures\FailedResult;
use App\Services\Invoices\Payments\MoneyhashService;
use App\Services\PaymentProviderCustomers\MoneyhashService as MoneyhashCustomerService;

/**
 * Port of Rails' PaymentProviders::Moneyhash::HandleEventService:
 *  - intent.processed / intent.time_expired and
 *    transaction.purchase.{failed,pending_authentication,successful} move
 *    the invoice payment status (MoneyHash status -> Lago payable map);
 *  - card_token.created / .updated / .deleted maintain the customer's
 *    payment method;
 *  - any other event code service-fails with "webhook_error"; a PaymentRequest
 *    payable has its own (unported) services and an unknown type is Rails'
 *    NameError.
 */
class HandleEventService extends BaseService
{
    public const INTENT_WEBHOOKS_EVENTS = ['intent.processed', 'intent.time_expired'];

    public const TRANSACTION_WEBHOOKS_EVENTS = [
        'transaction.purchase.failed',
        'transaction.purchase.pending_authentication',
        'transaction.purchase.successful',
    ];

    public const CARD_WEBHOOKS_EVENTS = ['card_token.created', 'card_token.updated', 'card_token.deleted'];

    public function __construct(
        private readonly Organization $organization,
        private readonly string $eventJson,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        $event = json_decode($this->eventJson, true, 512, JSON_THROW_ON_ERROR);

        $eventCode = (string) ($event['type'] ?? '');

        if (! in_array($eventCode, self::allowedWebhookEvents(), true)) {
            return $result->serviceFailure(
                code: 'webhook_error',
                message: "Invalid moneyhash event code: {$eventCode}",
            );
        }

        try {
            return match ($eventCode) {
                'intent.processed', 'intent.time_expired' => $this->handleIntentEvent($event),
                'transaction.purchase.failed',
                'transaction.purchase.pending_authentication',
                'transaction.purchase.successful' => $this->handleTransactionEvent($event),
                'card_token.created', 'card_token.updated', 'card_token.deleted' => $this->handleCardEvent($event),
                default => $result->serviceFailure(
                    code: 'webhook_error',
                    message: "No handler for event code: {$eventCode}",
                ),
            };
        } catch (FailedResult $e) {
            return $e->result;
        } catch (Throwable $e) {
            throw $e;
        }
    }

    /** @return list<string> */
    private static function allowedWebhookEvents(): array
    {
        return array_merge(
            self::INTENT_WEBHOOKS_EVENTS,
            self::TRANSACTION_WEBHOOKS_EVENTS,
            self::CARD_WEBHOOKS_EVENTS,
        );
    }

    /**
     * Rails: intent_amount_cents — Money.from_amount(value.to_d,
     * currency).cents; the currency comes from inside the hash for
     * transaction events, from the sibling field for intent events.
     */
    private static function intentAmountCents(mixed $raw, mixed $currencyFallback): ?int
    {
        if ($raw === null) {
            return null;
        }

        if (is_array($raw)) {
            $value = $raw['value'] ?? null;
            $currency = $raw['currency'] ?? null;
        } else {
            $value = $raw;
            $currency = $currencyFallback;
        }

        if ($value === null || $currency === null) {
            return null;
        }

        return MoneyhashCustomerService::amountToCents($value);
    }

    /** Rails: event_to_payment_status — the MH event -> MH payment status. */
    private static function eventToPaymentStatus(string $eventCode): string
    {
        return match ($eventCode) {
            'intent.processed', 'transaction.purchase.successful' => 'SUCCESSFUL',
            'intent.time_expired', 'transaction.purchase.failed' => 'FAILED',
            'transaction.purchase.pending_authentication' => 'PENDING',
            default => '',
        };
    }

    /**
     * Rails: extract_card_details — the PaymentMethods::CardDetails shape.
     *
     * @param  array<string, mixed>  $cardToken
     * @return array<string, mixed>
     */
    private static function extractCardDetails(array $cardToken): array
    {
        return array_filter([
            'type' => $cardToken['type'] ?? null,
            'last4' => $cardToken['last_4'] ?? null,
            'brand' => $cardToken['brand'] ?? null,
            'expiration_month' => $cardToken['expiry_month'] ?? null,
            'expiration_year' => $cardToken['expiry_year'] ?? null,
            'card_holder_name' => $cardToken['card_holder_name'] ?? null,
            'issuer' => $cardToken['issuer'] ?? null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * Rails: handle_intent_event — data.intent_id is the provider payment
     * id, data.intent.custom_fields the metadata; the amount is a scalar
     * with data.intent.amount_currency as its sibling.
     *
     * @param  array<string, mixed>  $event
     */
    private function handleIntentEvent(array $event): BaseResult
    {
        MoneyhashService::updatePaymentStatus(
            organizationId: $this->organization->id,
            providerPaymentId: (string) ($event['data']['intent_id'] ?? ''),
            status: self::eventToPaymentStatus((string) ($event['type'] ?? '')),
            amountCents: self::intentAmountCents(
                $event['data']['intent']['amount'] ?? null,
                $event['data']['intent']['amount_currency'] ?? null,
            ),
            metadata: $event['data']['intent']['custom_fields'] ?? [],
        )->raiseIfError();

        return static::makeResult();
    }

    /**
     * Rails: handle_transaction_event — intent.id is the provider payment
     * id, intent.custom_fields the metadata; the amount is a hash
     * {"value" => .., "currency" => ..}.
     *
     * @param  array<string, mixed>  $event
     */
    private function handleTransactionEvent(array $event): BaseResult
    {
        MoneyhashService::updatePaymentStatus(
            organizationId: $this->organization->id,
            providerPaymentId: (string) ($event['intent']['id'] ?? ''),
            status: self::eventToPaymentStatus((string) ($event['type'] ?? '')),
            amountCents: self::intentAmountCents($event['intent']['amount'] ?? null, null),
            metadata: $event['intent']['custom_fields'] ?? [],
        )->raiseIfError();

        return static::makeResult();
    }

    /**
     * Rails: handle_card_event.
     *
     * @param  array<string, mixed>  $event
     */
    private function handleCardEvent(array $event): BaseResult
    {
        $result = static::makeResult();
        $eventCode = (string) ($event['type'] ?? '');
        $cardToken = $event['data']['card_token'] ?? null;

        if (! is_array($cardToken)) {
            return $result;
        }

        if ($eventCode === 'card_token.deleted') {
            MoneyhashCustomerService::deletePaymentMethod(
                organizationId: $this->organization->id,
                customerId: $cardToken['custom_fields']['lago_customer_id'] ?? null,
                paymentMethodId: (string) ($cardToken['id'] ?? ''),
                metadata: $cardToken['custom_fields'] ?? [],
            )->raiseIfError();

            return $result;
        }

        MoneyhashCustomerService::updatePaymentMethod(
            organizationId: $this->organization->id,
            customerId: $cardToken['custom_fields']['lago_customer_id'] ?? null,
            paymentMethodId: (string) ($cardToken['id'] ?? ''),
            metadata: $cardToken['custom_fields'] ?? [],
            cardDetails: self::extractCardDetails($cardToken),
        )->raiseIfError();

        return $result;
    }
}
