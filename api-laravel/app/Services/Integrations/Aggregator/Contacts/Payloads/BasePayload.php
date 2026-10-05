<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Contacts\Payloads;

use App\Models\Customer;
use App\Models\Integration;
use App\Models\IntegrationCustomer;
use App\Services\Integrations\Aggregator\BasePayload as AggregatorBasePayload;

/**
 * Port of Rails' Integrations::Aggregator::Contacts::Payloads::BasePayload
 * — the shared contact shape (the Anrok/Avalara payloads only override the
 * name).
 */
abstract class BasePayload extends AggregatorBasePayload
{
    public function __construct(
        Integration $integration,
        public readonly Customer $customer,
        public readonly ?IntegrationCustomer $integration_customer = null,
        public readonly ?string $subsidiary_id = null,
    ) {
        parent::__construct($integration, $customer->billingEntity);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function create_body(): array
    {
        return [
            array_merge([
                'name' => $this->customer->name,
                'city' => $this->customer->city,
                'zip' => $this->customer->zipcode,
                'country' => $this->customer->country,
                'state' => $this->customer->state,
                'email' => $this->email(),
                'phone' => $this->phone(),
            ], $this->contact_names()),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function update_body(): array
    {
        return [
            array_merge([
                'id' => $this->integration_customer?->external_customer_id,
                'name' => $this->customer->name,
                'city' => $this->customer->city,
                'zip' => $this->customer->zipcode,
                'country' => $this->customer->country,
                'state' => $this->customer->state,
                'email' => $this->email(),
                'phone' => $this->phone(),
            ], $this->contact_names()),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    protected function contact_names(): array
    {
        $names = [];

        if ($this->customer->firstname !== null && $this->customer->firstname !== '') {
            $names['firstname'] = $this->customer->firstname;
        }

        if ($this->customer->lastname !== null && $this->customer->lastname !== '') {
            $names['lastname'] = $this->customer->lastname;
        }

        return $names;
    }

    protected function email(): ?string
    {
        $first = explode(',', (string) $this->customer->email)[0] ?? '';

        return mb_trim($first) !== '' ? mb_trim($first) : null;
    }

    protected function phone(): ?string
    {
        $first = explode(',', (string) $this->customer->phone)[0] ?? '';

        return mb_trim($first) !== '' ? mb_trim($first) : null;
    }

    /** Rails: `customer.display_name(prefer_legal_name: false)`. */
    protected function display_name(): ?string
    {
        $names = [$this->customer->name];

        if (($this->customer->firstname ?? '') !== '' || ($this->customer->lastname ?? '') !== '') {
            if ($names[0] !== null && $names[0] !== '') {
                $names[] = '-';
            }

            $names[] = $this->customer->firstname;
            $names[] = $this->customer->lastname;
        }

        return mb_trim(implode(' ', array_filter($names, fn ($n) => $n !== null && $n !== ''))) ?: null;
    }

    /**
     * Rails: `customer_url` — the front-end link to the customer
     * (config.lago_front_url joined with the org slug and the customer id).
     */
    protected function customer_url(): string
    {
        $url = (string) config('lago.front_url');

        $base = mb_rtrim($url, '/').'/';

        return $base.$this->customer->organization->slug.'/customer/'.$this->customer->id;
    }

    /**
     * Rails: `clean_url` — the host of the url, scheme-prefixed first when
     * missing; nil for anything unparseable.
     */
    protected function clean_url(mixed $url): ?string
    {
        if ($url === null || (string) $url === '') {
            return null;
        }

        $url = (string) $url;

        if (preg_match('/\Ahttps?:\/\//', $url) !== 1) {
            $url = 'http://'.$url;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
