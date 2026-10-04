<?php

declare(strict_types=1);

namespace App\Models\Analytics;

/**
 * Port of Rails' Analytics::InvoiceCollection
 * (app/models/analytics/invoice_collection.rb) — finalized invoices split
 * by payment status per month. Premium-gated at the service level.
 */
class InvoiceCollection extends Base
{
    /**
     * @param  array<string, mixed>  $args  billing_entity_id, external_customer_id,
     *                                      is_customer_tin_empty, months, currency
     */
    public static function query(string $organizationId, array $args = []): string
    {
        $andBillingEntityIdSql = '';
        if (($args['billing_entity_id'] ?? null) !== null && $args['billing_entity_id'] !== '') {
            $andBillingEntityIdSql = 'AND i.billing_entity_id = :billing_entity_id';
        }

        $andExternalCustomerIdSql = '';
        if (($args['external_customer_id'] ?? null) !== null && $args['external_customer_id'] !== '') {
            $andExternalCustomerIdSql = 'AND c.external_id = :external_customer_id AND c.deleted_at IS NULL';
        }

        $andIsCustomerTinEmptySql = '';
        if (($args['is_customer_tin_empty'] ?? null) !== null) {
            $andIsCustomerTinEmptySql = ($args['is_customer_tin_empty'] === true)
                ? "AND (c.tax_identification_number IS NULL OR trim(c.tax_identification_number) = '')"
                : "AND (c.tax_identification_number IS NOT NULL AND trim(c.tax_identification_number) <> '')";
        }

        $andMonthsSql = '';
        if (($args['months'] ?? null) !== null && $args['months'] !== '') {
            $monthsInterval = ((int) $args['months'] <= 1) ? 0 : ((int) $args['months'] - 1);
            $andMonthsSql = "AND am.month >= DATE_TRUNC('month', CURRENT_DATE - INTERVAL '".$monthsInterval." months')";
        }

        $andCurrencySql = '';
        if (($args['currency'] ?? null) !== null && $args['currency'] !== '') {
            $andCurrencySql = 'AND currency = '.static::quoteString(mb_strtoupper((string) $args['currency']));
        }

        $sql = str_replace([
            '{and_external_customer_id_sql}',
            '{and_is_customer_tin_empty_sql}',
            '{and_billing_entity_id_sql}',
            '{and_months_sql}',
            '{and_currency_sql}',
        ], [
            $andExternalCustomerIdSql,
            $andIsCustomerTinEmptySql,
            $andBillingEntityIdSql,
            $andMonthsSql,
            $andCurrencySql,
        ], <<<'SQL'
            WITH organization_creation_date AS (
              SELECT
                DATE_TRUNC('month', o.created_at) AS start_month
              FROM organizations o
              WHERE o.id = :organization_id
            ),
            all_months AS (
              SELECT
                generate_series(
                  (SELECT start_month FROM organization_creation_date),
                  DATE_TRUNC('month', CURRENT_DATE + INTERVAL '10 years'),
                  interval '1 month'
                ) AS month
            ),
            invoices_per_status AS (
              SELECT
                DATE_TRUNC('month', i.issuing_date) AS month,
                i.currency,
                CASE
                  WHEN i.payment_status = 0 THEN 'pending'
                  WHEN i.payment_status = 1 THEN 'succeeded'
                  WHEN i.payment_status = 2 THEN 'failed'
                END AS payment_status,
                COALESCE(COUNT(*), 0) AS invoices_count,
                COALESCE(SUM(i.total_amount_cents::float), 0) AS amount_cents
              FROM invoices i
              LEFT JOIN customers c ON i.customer_id = c.id
              WHERE i.organization_id = :organization_id
              AND i.self_billed IS FALSE
              AND i.status = 1
              AND i.payment_dispute_lost_at IS NULL
              {and_external_customer_id_sql}
              {and_is_customer_tin_empty_sql}
              {and_billing_entity_id_sql}
              GROUP BY payment_status, month, i.currency
            )
            SELECT
              am.month,
              payment_status,
              ips.currency,
              COALESCE(invoices_count, 0) AS invoices_count,
              COALESCE(amount_cents, 0) AS amount_cents
            FROM all_months am
            LEFT JOIN invoices_per_status ips ON ips.month = am.month AND ips.payment_status IS NOT NULL
            WHERE am.month <= DATE_TRUNC('month', CURRENT_DATE)
            {and_months_sql}
            {and_currency_sql}
            ORDER BY am.month, payment_status, ips.currency
        SQL);

        return static::sanitizeSql($sql, ['organization_id' => $organizationId, ...$args]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public static function cacheKey(string $organizationId, array $args = []): string
    {
        return implode('/', array_map(
            fn (mixed $part): string => is_bool($part) ? ($part ? 'true' : 'false') : (string) $part,
            [
                'invoice-collection',
                now()->format('Y-m-d'),
                $organizationId,
                $args['billing_entity_id'] ?? '',
                $args['external_customer_id'] ?? '',
                $args['currency'] ?? '',
                $args['months'] ?? '',
                $args['is_customer_tin_empty'] ?? '',
            ],
        ));
    }
}
