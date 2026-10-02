<?php

declare(strict_types=1);

namespace App\GraphQL\Support;

/**
 * Wire mapping for the frozen SDL's TimezoneEnum (port of Rails'
 * Types::TimezoneEnum): the wire carries TZ_* symbol names while resolvers
 * hold real IANA identifiers ("Europe/Paris"). "Etc/UTC" is special-cased
 * to the "UTC" wire name on both sides, exactly like Rails.
 *
 * The map is generated from the frozen SDL's TimezoneEnum descriptions
 * (Rails' enum is itself generated from the tz database).
 */
final class TimezoneWire
{
    /** @var array<string, string> wire symbol => IANA identifier. */
    public const WIRE_TO_IDENTIFIER = [
        'TZ_AFRICA_ALGIERS' => 'Africa/Algiers',
        'TZ_AFRICA_CAIRO' => 'Africa/Cairo',
        'TZ_AFRICA_CASABLANCA' => 'Africa/Casablanca',
        'TZ_AFRICA_HARARE' => 'Africa/Harare',
        'TZ_AFRICA_JOHANNESBURG' => 'Africa/Johannesburg',
        'TZ_AFRICA_MONROVIA' => 'Africa/Monrovia',
        'TZ_AFRICA_NAIROBI' => 'Africa/Nairobi',
        'TZ_AMERICA_ARGENTINA_BUENOS_AIRES' => 'America/Argentina/Buenos_Aires',
        'TZ_AMERICA_BOGOTA' => 'America/Bogota',
        'TZ_AMERICA_CARACAS' => 'America/Caracas',
        'TZ_AMERICA_CHICAGO' => 'America/Chicago',
        'TZ_AMERICA_CHIHUAHUA' => 'America/Chihuahua',
        'TZ_AMERICA_DENVER' => 'America/Denver',
        'TZ_AMERICA_GUATEMALA' => 'America/Guatemala',
        'TZ_AMERICA_GUYANA' => 'America/Guyana',
        'TZ_AMERICA_HALIFAX' => 'America/Halifax',
        'TZ_AMERICA_INDIANA_INDIANAPOLIS' => 'America/Indiana/Indianapolis',
        'TZ_AMERICA_JUNEAU' => 'America/Juneau',
        'TZ_AMERICA_LA_PAZ' => 'America/La_Paz',
        'TZ_AMERICA_LIMA' => 'America/Lima',
        'TZ_AMERICA_LOS_ANGELES' => 'America/Los_Angeles',
        'TZ_AMERICA_MAZATLAN' => 'America/Mazatlan',
        'TZ_AMERICA_MEXICO_CITY' => 'America/Mexico_City',
        'TZ_AMERICA_MONTERREY' => 'America/Monterrey',
        'TZ_AMERICA_MONTEVIDEO' => 'America/Montevideo',
        'TZ_AMERICA_NEW_YORK' => 'America/New_York',
        'TZ_AMERICA_NUUK' => 'America/Nuuk',
        'TZ_AMERICA_PHOENIX' => 'America/Phoenix',
        'TZ_AMERICA_PUERTO_RICO' => 'America/Puerto_Rico',
        'TZ_AMERICA_REGINA' => 'America/Regina',
        'TZ_AMERICA_SANTIAGO' => 'America/Santiago',
        'TZ_AMERICA_SAO_PAULO' => 'America/Sao_Paulo',
        'TZ_AMERICA_ST_JOHNS' => 'America/St_Johns',
        'TZ_AMERICA_TIJUANA' => 'America/Tijuana',
        'TZ_ASIA_ALMATY' => 'Asia/Almaty',
        'TZ_ASIA_BAGHDAD' => 'Asia/Baghdad',
        'TZ_ASIA_BAKU' => 'Asia/Baku',
        'TZ_ASIA_BANGKOK' => 'Asia/Bangkok',
        'TZ_ASIA_CHONGQING' => 'Asia/Chongqing',
        'TZ_ASIA_COLOMBO' => 'Asia/Colombo',
        'TZ_ASIA_DHAKA' => 'Asia/Dhaka',
        'TZ_ASIA_HONG_KONG' => 'Asia/Hong_Kong',
        'TZ_ASIA_IRKUTSK' => 'Asia/Irkutsk',
        'TZ_ASIA_JAKARTA' => 'Asia/Jakarta',
        'TZ_ASIA_JERUSALEM' => 'Asia/Jerusalem',
        'TZ_ASIA_KABUL' => 'Asia/Kabul',
        'TZ_ASIA_KAMCHATKA' => 'Asia/Kamchatka',
        'TZ_ASIA_KARACHI' => 'Asia/Karachi',
        'TZ_ASIA_KATHMANDU' => 'Asia/Kathmandu',
        'TZ_ASIA_KOLKATA' => 'Asia/Kolkata',
        'TZ_ASIA_KRASNOYARSK' => 'Asia/Krasnoyarsk',
        'TZ_ASIA_KUALA_LUMPUR' => 'Asia/Kuala_Lumpur',
        'TZ_ASIA_KUWAIT' => 'Asia/Kuwait',
        'TZ_ASIA_MAGADAN' => 'Asia/Magadan',
        'TZ_ASIA_MUSCAT' => 'Asia/Muscat',
        'TZ_ASIA_NOVOSIBIRSK' => 'Asia/Novosibirsk',
        'TZ_ASIA_RIYADH' => 'Asia/Riyadh',
        'TZ_ASIA_SEOUL' => 'Asia/Seoul',
        'TZ_ASIA_SHANGHAI' => 'Asia/Shanghai',
        'TZ_ASIA_SINGAPORE' => 'Asia/Singapore',
        'TZ_ASIA_SREDNEKOLYMSK' => 'Asia/Srednekolymsk',
        'TZ_ASIA_TAIPEI' => 'Asia/Taipei',
        'TZ_ASIA_TASHKENT' => 'Asia/Tashkent',
        'TZ_ASIA_TBILISI' => 'Asia/Tbilisi',
        'TZ_ASIA_TEHRAN' => 'Asia/Tehran',
        'TZ_ASIA_TOKYO' => 'Asia/Tokyo',
        'TZ_ASIA_ULAANBAATAR' => 'Asia/Ulaanbaatar',
        'TZ_ASIA_URUMQI' => 'Asia/Urumqi',
        'TZ_ASIA_VLADIVOSTOK' => 'Asia/Vladivostok',
        'TZ_ASIA_YAKUTSK' => 'Asia/Yakutsk',
        'TZ_ASIA_YANGON' => 'Asia/Yangon',
        'TZ_ASIA_YEKATERINBURG' => 'Asia/Yekaterinburg',
        'TZ_ASIA_YEREVAN' => 'Asia/Yerevan',
        'TZ_ATLANTIC_AZORES' => 'Atlantic/Azores',
        'TZ_ATLANTIC_CAPE_VERDE' => 'Atlantic/Cape_Verde',
        'TZ_ATLANTIC_SOUTH_GEORGIA' => 'Atlantic/South_Georgia',
        'TZ_AUSTRALIA_ADELAIDE' => 'Australia/Adelaide',
        'TZ_AUSTRALIA_BRISBANE' => 'Australia/Brisbane',
        'TZ_AUSTRALIA_CANBERRA' => 'Australia/Canberra',
        'TZ_AUSTRALIA_DARWIN' => 'Australia/Darwin',
        'TZ_AUSTRALIA_HOBART' => 'Australia/Hobart',
        'TZ_AUSTRALIA_MELBOURNE' => 'Australia/Melbourne',
        'TZ_AUSTRALIA_PERTH' => 'Australia/Perth',
        'TZ_AUSTRALIA_SYDNEY' => 'Australia/Sydney',
        'TZ_ETC_GMT_12' => 'Etc/GMT+12',
        'TZ_EUROPE_AMSTERDAM' => 'Europe/Amsterdam',
        'TZ_EUROPE_ATHENS' => 'Europe/Athens',
        'TZ_EUROPE_BELGRADE' => 'Europe/Belgrade',
        'TZ_EUROPE_BERLIN' => 'Europe/Berlin',
        'TZ_EUROPE_BRATISLAVA' => 'Europe/Bratislava',
        'TZ_EUROPE_BRUSSELS' => 'Europe/Brussels',
        'TZ_EUROPE_BUCHAREST' => 'Europe/Bucharest',
        'TZ_EUROPE_BUDAPEST' => 'Europe/Budapest',
        'TZ_EUROPE_COPENHAGEN' => 'Europe/Copenhagen',
        'TZ_EUROPE_DUBLIN' => 'Europe/Dublin',
        'TZ_EUROPE_HELSINKI' => 'Europe/Helsinki',
        'TZ_EUROPE_ISTANBUL' => 'Europe/Istanbul',
        'TZ_EUROPE_KALININGRAD' => 'Europe/Kaliningrad',
        'TZ_EUROPE_KYIV' => 'Europe/Kyiv',
        'TZ_EUROPE_LISBON' => 'Europe/Lisbon',
        'TZ_EUROPE_LJUBLJANA' => 'Europe/Ljubljana',
        'TZ_EUROPE_LONDON' => 'Europe/London',
        'TZ_EUROPE_MADRID' => 'Europe/Madrid',
        'TZ_EUROPE_MINSK' => 'Europe/Minsk',
        'TZ_EUROPE_MOSCOW' => 'Europe/Moscow',
        'TZ_EUROPE_PARIS' => 'Europe/Paris',
        'TZ_EUROPE_PRAGUE' => 'Europe/Prague',
        'TZ_EUROPE_RIGA' => 'Europe/Riga',
        'TZ_EUROPE_ROME' => 'Europe/Rome',
        'TZ_EUROPE_SAMARA' => 'Europe/Samara',
        'TZ_EUROPE_SARAJEVO' => 'Europe/Sarajevo',
        'TZ_EUROPE_SKOPJE' => 'Europe/Skopje',
        'TZ_EUROPE_SOFIA' => 'Europe/Sofia',
        'TZ_EUROPE_STOCKHOLM' => 'Europe/Stockholm',
        'TZ_EUROPE_TALLINN' => 'Europe/Tallinn',
        'TZ_EUROPE_VIENNA' => 'Europe/Vienna',
        'TZ_EUROPE_VILNIUS' => 'Europe/Vilnius',
        'TZ_EUROPE_VOLGOGRAD' => 'Europe/Volgograd',
        'TZ_EUROPE_WARSAW' => 'Europe/Warsaw',
        'TZ_EUROPE_ZAGREB' => 'Europe/Zagreb',
        'TZ_EUROPE_ZURICH' => 'Europe/Zurich',
        'TZ_PACIFIC_APIA' => 'Pacific/Apia',
        'TZ_PACIFIC_AUCKLAND' => 'Pacific/Auckland',
        'TZ_PACIFIC_CHATHAM' => 'Pacific/Chatham',
        'TZ_PACIFIC_FAKAOFO' => 'Pacific/Fakaofo',
        'TZ_PACIFIC_FIJI' => 'Pacific/Fiji',
        'TZ_PACIFIC_GUADALCANAL' => 'Pacific/Guadalcanal',
        'TZ_PACIFIC_GUAM' => 'Pacific/Guam',
        'TZ_PACIFIC_HONOLULU' => 'Pacific/Honolulu',
        'TZ_PACIFIC_MAJURO' => 'Pacific/Majuro',
        'TZ_PACIFIC_MIDWAY' => 'Pacific/Midway',
        'TZ_PACIFIC_NOUMEA' => 'Pacific/Noumea',
        'TZ_PACIFIC_PAGO_PAGO' => 'Pacific/Pago_Pago',
        'TZ_PACIFIC_PORT_MORESBY' => 'Pacific/Port_Moresby',
        'TZ_PACIFIC_TONGATAPU' => 'Pacific/Tongatapu',
        'TZ_UTC' => 'UTC',
    ];

    /** IANA identifier (or "UTC") => wire symbol; null/blank passes through as null. */
    public static function toWire(?string $timezone): ?string
    {
        if ($timezone === null || $timezone === '') {
            return null;
        }

        if ($timezone === 'UTC' || $timezone === 'Etc/UTC') {
            // Rails: symbol "UTC", value "UTC" — still emitted as TZ_UTC.
            return 'TZ_UTC';
        }

        return 'TZ_'.mb_strtoupper((string) preg_replace('/_+/', '_', (string) preg_replace('/[^_a-zA-Z0-9]/', '_', $timezone)));
    }

    /** Wire symbol => IANA identifier (for enum-typed mutation inputs). */
    public static function fromWire(?string $wire): ?string
    {
        if ($wire === null || $wire === '') {
            return null;
        }

        if ($wire === 'UTC') {
            return 'UTC';
        }

        return self::WIRE_TO_IDENTIFIER[$wire] ?? $wire;
    }
}
