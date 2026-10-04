<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * products.product_type — native Postgres enum `product_type`
 * ('metered', 'fixed'). String-backed values match the PG enum labels
 * exactly (Rails: `PRODUCT_TYPES`).
 */
enum ProductType: string
{
    case Metered = 'metered';
    case Fixed = 'fixed';
}
