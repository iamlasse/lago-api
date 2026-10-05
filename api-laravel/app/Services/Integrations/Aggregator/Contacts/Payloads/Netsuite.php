<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Contacts\Payloads;

use App\Models\Customer;
use App\Enums\CustomerType;

/**
 * Port of Rails' Integrations::Aggregator::Contacts::Payloads::Netsuite
 * (…/aggregator/contacts/payloads/netsuite.rb) — the restlet-script
 * customer record shape (columns + the address book lines).
 */
final class Netsuite extends BasePayload
{
    public const int BASE_LIMIT = 30;

    public const int ADDR1_LIMIT = 150;

    public const int CITY_LIMIT = 50;

    /**
     * @return array<string, mixed>
     */
    public function create_body(): array
    {
        return array_merge([
            'type' => 'customer', // Fixed value
            'isDynamic' => true, // Fixed value
            'columns' => array_merge([
                'isperson' => $this->isperson(),
                'subsidiary' => $this->subsidiary_id,
                'custentity_lago_id' => $this->customer->id,
                'custentity_lago_sf_id' => $this->customer->external_salesforce_id,
                'custentity_lago_customer_link' => $this->customer_url(),
                'email' => $this->email(),
                'phone' => $this->phone(),
                'entityid' => $this->customer->external_id,
                'autoname' => false, // fixed value
            ], $this->names()),
            'options' => [
                'ignoreMandatoryFields' => false, // Fixed value
            ],
        ], $this->include_lines() ? ['lines' => $this->lines()] : []);
    }

    /**
     * @return array<string, mixed>
     */
    public function update_body(): array
    {
        return [
            'type' => 'customer',
            'recordId' => $this->integration_customer?->external_customer_id,
            'columns' => array_merge([
                'isperson' => $this->isperson(),
                'subsidiary' => $this->integration_customer?->subsidiaryId(),
                'custentity_lago_sf_id' => $this->customer->external_salesforce_id,
                'custentity_lago_customer_link' => $this->customer_url(),
                'email' => $this->email(),
                'phone' => $this->phone(),
                'entityid' => $this->customer->external_id,
                'autoname' => false, // fixed value
            ], $this->names()),
            'options' => [
                'isDynamic' => false,
            ],
        ];
    }

    /** Rails: `customer_url` — the front-app deep link of the customer. */
    protected function customer_url(): string
    {
        $url = mb_rtrim((string) config('lago.front_url'), '/');

        return $url.'/'.$this->customer->organization->slug.'/customer/'.$this->customer->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function names(): array
    {
        // customer_type might be null -> in that case it's a company so we
        // better check for an individual type here.
        if (! $this->customer_type_individual()) {
            return ['companyname' => $this->customer->name];
        }

        $namesHash = [
            'firstname' => $this->truncate((string) $this->customer->firstname, self::BASE_LIMIT),
            'lastname' => $this->truncate((string) $this->customer->lastname, self::BASE_LIMIT),
        ];

        if (($this->customer->name ?? '') !== '') {
            $namesHash['companyname'] = $this->customer->name;
        }

        return $namesHash;
    }

    private function isperson(): string
    {
        return $this->customer_type_individual() ? 'T' : 'F';
    }

    private function customer_type_individual(): bool
    {
        return $this->customer->customer_type === CustomerType::Individual;
    }

    private function include_lines(): bool
    {
        return ! $this->integration->getFromSettings('legacy_script')
            && ! $this->empty_billing_and_shipping_address();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(): array
    {
        if ($this->same_billing_and_shipping_address()) {
            return [
                [
                    'lineItems' => [
                        [
                            'defaultshipping' => true,
                            'defaultbilling' => true,
                            'subObjectId' => 'addressbookaddress',
                            'subObject' => [
                                'addr1' => $this->truncate((string) $this->customer->address_line1, self::ADDR1_LIMIT),
                                'addr2' => $this->customer->address_line2,
                                'city' => $this->truncate((string) $this->customer->city, self::CITY_LIMIT),
                                'zip' => $this->customer->zipcode,
                                'state' => $this->truncate((string) $this->customer->state, self::BASE_LIMIT),
                                'country' => $this->customer->country,
                            ],
                        ],
                    ],
                    'sublistId' => 'addressbook',
                ],
            ];
        }

        return [
            [
                'lineItems' => [
                    [
                        'defaultshipping' => false,
                        'defaultbilling' => true,
                        'subObjectId' => 'addressbookaddress',
                        'subObject' => [
                            'addr1' => $this->truncate((string) $this->customer->address_line1, self::ADDR1_LIMIT),
                            'addr2' => $this->customer->address_line2,
                            'city' => $this->truncate((string) $this->customer->city, self::CITY_LIMIT),
                            'zip' => $this->customer->zipcode,
                            'state' => $this->truncate((string) $this->customer->state, self::BASE_LIMIT),
                            'country' => $this->customer->country,
                        ],
                    ],
                    [
                        'defaultshipping' => true,
                        'defaultbilling' => false,
                        'subObjectId' => 'addressbookaddress',
                        'subObject' => [
                            'addr1' => $this->truncate((string) $this->customer->shipping_address_line1, self::ADDR1_LIMIT),
                            'addr2' => $this->customer->shipping_address_line2,
                            'city' => $this->truncate((string) $this->customer->shipping_city, self::CITY_LIMIT),
                            'zip' => $this->customer->shipping_zipcode,
                            'state' => $this->truncate((string) $this->customer->shipping_state, self::BASE_LIMIT),
                            'country' => $this->customer->shipping_country,
                        ],
                    ],
                ],
                'sublistId' => 'addressbook',
            ],
        ];
    }

    /** Rails: Customer#same_billing_and_shipping_address?. */
    private function same_billing_and_shipping_address(): bool
    {
        $customer = $this->customer;

        $shipping = [
            $customer->shipping_address_line1,
            $customer->shipping_address_line2,
            $customer->shipping_city,
            $customer->shipping_zipcode,
            $customer->shipping_state,
            $customer->shipping_country,
        ];

        if (collect($shipping)->every(fn ($value) => $value === null || $value === '')) {
            return true;
        }

        return $customer->address_line1 === $customer->shipping_address_line1
            && $customer->address_line2 === $customer->shipping_address_line2
            && $customer->city === $customer->shipping_city
            && $customer->zipcode === $customer->shipping_zipcode
            && $customer->state === $customer->shipping_state
            && $customer->country === $customer->shipping_country;
    }

    /** Rails: Customer#empty_billing_and_shipping_address?. */
    private function empty_billing_and_shipping_address(): bool
    {
        $customer = $this->customer;

        $shipping = [
            $customer->shipping_address_line1,
            $customer->shipping_address_line2,
            $customer->shipping_city,
            $customer->shipping_zipcode,
            $customer->shipping_state,
            $customer->shipping_country,
        ];

        return collect($shipping)->every(fn ($value) => $value === null || $value === '')
            && ($customer->address_line1 === null || $customer->address_line1 === '')
            && ($customer->address_line2 === null || $customer->address_line2 === '')
            && ($customer->city === null || $customer->city === '')
            && ($customer->zipcode === null || $customer->zipcode === '')
            && ($customer->state === null || $customer->state === '')
            && ($customer->country === null || $customer->country === '');
    }

    /** Rails: `string.first(limit)`. */
    private function truncate(string $value, int $limit): ?string
    {
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }
}
