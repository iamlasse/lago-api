<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Endpoints;

use Throwable;
use App\Models\WebhookEndpoint;
use App\Http\Client\AddressGuard;
use App\Http\Client\BlockedAddressError;
use Illuminate\Http\Client\ConnectionException;

/**
 * Port of the WebhookEndpoint model's validations and the
 * before_validation :normalize_event_types callback
 * (app/models/webhook_endpoint.rb), extracted here because the
 * frozen-schema model (app/Models/WebhookEndpoint.php) carries no
 * validation logic. The messages are the Rails i18n renderings
 * (config/locales/en.yml): blank -> value_is_mandatory, taken ->
 * value_already_exist, url_invalid -> url_is_invalid, must_be_array,
 * 'contains invalid types: %{invalid_types}'.
 */
final class EndpointValidations
{
    /**
     * Rails: WEBHOOK_EVENT_TYPES — config/webhook_event_types.yml `name`
     * entries, verbatim.
     *
     * @return list<string>
     */
    public static function webhookEventTypes(): array
    {
        return [
            'alert.triggered',
            'billable_metric.created',
            'billable_metric.updated',
            'billable_metric.deleted',
            'customer.created',
            'customer.updated',
            'customer.accounting_provider_created',
            'customer.accounting_provider_error',
            'customer.crm_provider_created',
            'customer.crm_provider_error',
            'customer.payment_provider_created',
            'customer.payment_provider_error',
            'customer.checkout_url_generated',
            'customer.tax_provider_error',
            'customer.vies_check',
            'credit_note.created',
            'credit_note.generated',
            'credit_note.provider_refund_failure',
            'dunning_campaign.finished',
            'event.error',
            'events.errors',
            'feature.created',
            'feature.updated',
            'feature.deleted',
            'fee.created',
            'fee.tax_provider_error',
            'invoice.created',
            'invoice.one_off_created',
            'invoice.paid_credit_added',
            'invoice.generated',
            'invoice.drafted',
            'invoice.ready_to_finalize',
            'invoice.voided',
            'invoice.deleted',
            'invoice.payment_dispute_lost',
            'invoice.payment_status_updated',
            'invoice.payment_overdue',
            'invoice.payment_failure',
            'invoice.resynced',
            'integration.provider_error',
            'order.created',
            'order.executed',
            'order_form.created',
            'order_form.signed',
            'order_form.expired',
            'order_form.voided',
            'payment.succeeded',
            'payment.requires_action',
            'payment_provider.error',
            'payment_receipt.created',
            'payment_receipt.generated',
            'payment_request.created',
            'payment_request.payment_failure',
            'payment_request.payment_status_updated',
            'plan.created',
            'plan.updated',
            'plan.deleted',
            'quote.created',
            'quote.approved',
            'quote.voided',
            'subscription.canceled',
            'subscription.incomplete',
            'subscription.terminated',
            'subscription.started',
            'subscription.updated',
            'subscription.termination_alert',
            'subscription.trial_ended',
            'subscription.usage_threshold_reached',
            'wallet.created',
            'wallet.depleted_ongoing_balance',
            'wallet.terminated',
            'wallet.updated',
            'wallet_transaction.created',
            'wallet_transaction.updated',
            'wallet_transaction.payment_failure',
        ];
    }

    /**
     * Port of before_validation :normalize_event_types (the caller only
     * invokes it when the attribute changed, like the `if:
     * :event_types_changed?` gate): strip/downcase/uniq, drop blanks, and
     * the ["*"] special case -> null (filtering disabled).
     *
     * @return list<string>|null
     */
    public static function normalizeEventTypes(mixed $raw): ?array
    {
        if ($raw === null || ! is_array($raw)) {
            return null;
        }

        $normalized = array_values(array_unique(array_filter(
            array_map(
                fn ($type): string => is_scalar($type) ? mb_strtolower(mb_trim((string) $type)) : '',
                $raw,
            ),
            fn (string $type): bool => $type !== '',
        )));

        if ($normalized === ['*']) {
            return null;
        }

        return $normalized;
    }

    /**
     * Assigns event_types on the endpoint (normalized, like the
     * before_validation callback) and returns the resulting validation
     * messages. A non-array raw value assigns nothing and reports
     * must_be_array, like the Rails cast + raw-value check.
     *
     * @return list<string>
     */
    public static function assignEventTypes(WebhookEndpoint $endpoint, mixed $raw): array
    {
        $normalized = self::normalizeEventTypes($raw);

        if (is_array($raw) || $raw === null) {
            $endpoint->event_types = $normalized;
        }

        return self::validateEventTypes($raw, $normalized);
    }

    /**
     * Port of validate_event_types (run after normalization, when the
     * attribute changed) — the event_types messages, empty when valid.
     *
     * Rails stores the pg-array cast of a non-array value ([]) and reports
     * must_be_array from the raw value; nothing is assigned on that path,
     * which the failing save! makes equivalent.
     *
     * @return list<string>
     */
    public static function validateEventTypes(mixed $raw, ?array $normalized): array
    {
        if ($raw !== null && ! is_array($raw)) {
            return ['must_be_array'];
        }

        if ($normalized === null) {
            return [];
        }

        $invalidTypes = array_values(array_diff($normalized, self::webhookEventTypes()));

        if ($invalidTypes !== []) {
            return ['contains invalid types: '.self::rubyInspect($invalidTypes)];
        }

        return [];
    }

    /**
     * Port of the webhook_url validations: presence, the UrlValidator
     * format check (http/https with a host) including the
     * block_private_addresses branch (only when the attribute changed, and
     * only while the AddressGuard is enabled — an unresolvable host is
     * accepted, the HTTP client re-checks at request time), and the
     * uniqueness scope on organization_id.
     *
     * @return list<string>
     */
    public static function validateWebhookUrl(WebhookEndpoint $endpoint, string $webhookUrl): array
    {
        $errors = [];

        if (! self::urlValid($webhookUrl)
            || ($endpoint->isDirty('webhook_url') && self::privateAddress($webhookUrl))) {
            $errors[] = 'url_is_invalid';
        }

        $uniqueness = WebhookEndpoint::query()
            ->where('webhook_url', $webhookUrl)
            ->where('organization_id', $endpoint->organization_id);

        if ($endpoint->exists) {
            $uniqueness->where($endpoint->getKeyName(), '!=', $endpoint->getKey());
        }

        if ($uniqueness->exists()) {
            $errors[] = 'value_already_exist';
        }

        return $errors;
    }

    /** UrlValidator#url_valid? — a parseable http(s) URI with a host. */
    public static function urlValid(string $url): bool
    {
        $parts = @parse_url($url);

        if ($parts === false || ($parts['host'] ?? '') === '') {
            return false;
        }

        return in_array(mb_strtolower($parts['scheme'] ?? ''), ['http', 'https'], true);
    }

    /**
     * UrlValidator#private_address? — true when the host resolves to a
     * blocked range. Unresolvable hosts are accepted (Rails rescues
     * SocketError: the HTTP client checks the address again at request
     * time).
     */
    public static function privateAddress(string $url): bool
    {
        if (! AddressGuard::enabled()) {
            return false;
        }

        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');

        try {
            AddressGuard::resolve($host);

            return false;
        } catch (BlockedAddressError) {
            return true;
        } catch (ConnectionException) {
            // Rails: SocketError — accepted.
            return false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Ruby Array#inspect — the %{invalid_types} interpolation format.
     *
     * @param  list<string>  $values
     */
    public static function rubyInspect(array $values): string
    {
        return '['.implode(',', array_map(
            fn (string $value): string => json_encode($value, JSON_UNESCAPED_SLASHES),
            $values,
        )).']';
    }
}
