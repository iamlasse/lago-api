<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Adyen;

use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of the adyen-ruby-api-library HmacValidator as used by Rails'
 * PaymentProviders::Adyen::HandleIncomingWebhookService
 * (`::Adyen::Utils::HmacValidator.new.valid_notification_hmac?(body,
 * hmac_key)`), where `body` is the permitted NotificationRequestItem hash
 * from the controller.
 *
 * Algorithm (gem 7.3.1):
 *  - the signing data joins the item's pspReference, originalReference,
 *    merchantAccountCode, merchantReference, amount.value, amount.currency,
 *    eventCode and success with ':' (missing values are empty strings);
 *  - the HMAC key is the provider's hex hmac_key packed to raw bytes
 *    (gem: [hmac_key].pack('H*'));
 *  - the expected signature is base64(HMAC-SHA256(data, key)), compared
 *    with the item's additionalData.hmacSignature.
 *
 * A missing key or signature yields a "webhook_error" ServiceFailure (the
 * webhook route answers 400).
 */
class ValidateIncomingWebhookService extends BaseService
{
    /** Gem: HmacValidator::WEBHOOK_VALIDATION_KEYS. */
    private const array WEBHOOK_VALIDATION_KEYS = [
        'pspReference',
        'originalReference',
        'merchantAccountCode',
        'merchantReference',
        'amount.value',
        'amount.currency',
        'eventCode',
        'success',
    ];

    public function __construct(
        /** @var array<string, mixed> the NotificationRequestItem */
        private readonly array $body,
        private readonly ?string $hmacKey,
    ) {
        parent::__construct();
    }

    /** Gem: HmacValidator#valid_webhook_hmac? */
    public static function validHmac(array $notificationItem, string $hmacKey): bool
    {
        $expectedSign = self::calculateHmac($notificationItem, $hmacKey);
        $merchantSign = self::fetch($notificationItem, 'additionalData.hmacSignature');

        if ($merchantSign === null) {
            return false;
        }

        return hash_equals($expectedSign, (string) $merchantSign);
    }

    /** Gem: HmacValidator#calculate_webhook_hmac — base64 HMAC over ':'-joined fields. */
    public static function calculateHmac(array $notificationItem, string $hmacKey): string
    {
        $data = self::dataToSign($notificationItem);
        $packedKey = self::packHex($hmacKey);

        return base64_encode(hash_hmac('sha256', $data, $packedKey, true));
    }

    /** Gem: HmacValidator#data_to_sign. */
    public static function dataToSign(array $notificationItem): string
    {
        $values = array_map(
            fn (string $key): string => (string) self::fetch($notificationItem, $key),
            self::WEBHOOK_VALIDATION_KEYS,
        );

        return implode(':', $values);
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        if ($this->hmacKey === null || $this->hmacKey === ''
            || ! self::validHmac($this->body, $this->hmacKey)) {
            return $result->serviceFailure(code: 'webhook_error', message: 'Invalid signature');
        }

        return $result;
    }

    /**
     * Ruby's [key].pack('H*') — each pair of hex digits becomes a byte;
     * a trailing odd digit is dropped (gem behaviour).
     */
    private static function packHex(string $hex): string
    {
        $packed = @hex2bin($hex);

        return $packed === false ? '' : $packed;
    }

    /** Gem: HmacValidator#fetch — '.'-separated dig supporting symbol keys. */
    private static function fetch(array $hash, string $keys): mixed
    {
        $value = $hash;

        foreach (explode('.', $keys) as $key) {
            if (! is_array($value)) {
                return null;
            }

            $value = $value[$key] ?? null;
        }

        return $value;
    }
}
