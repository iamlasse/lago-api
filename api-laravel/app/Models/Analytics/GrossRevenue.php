<?php

declare(strict_types=1);

namespace App\Models\Analytics;

/**
 * Port of Rails' Analytics::GrossRevenue (app/models/analytics/gross_revenue.rb)
 * — issued invoices + pay-in-addvance instant charges, rolled into a
 * monthly per-currency (per-billing-entity) gross revenue series over the
 * organization's lifetime. Raw SQL on the primary connection, exactly like
 * Rails.
 */
class GrossRevenue extends Base
{
    /**
     * @param  array<string, mixed>  $args  billing_entity_id, external_customer_id, months, currency
     */
    public static function query(string $organizationId, array $args = []): string
    {
        $andBillingEntityIdSql = '';
        $andFeeBillingEntityIdSql = '';
        if (($args['billing_entity_id'] ?? null) !== null && $args['billing_entity_id'] !== '') {
            $andBillingEntityIdSql = 'AND i.billing_entity_id = :billing_entity_id';
            $andFeeBillingEntityIdSql = 'AND f.billing_entity_id = :billing_entity_id';
        }

        $andExternalCustomerIdSql = '';
        if (($args['external_customer_id'] ?? null) !== null && $args['external_customer_id'] !== '') {
            $andExternalCustomerIdSql = 'AND c.external_id = :external_customer_id AND c.deleted_at IS NULL';
        }

        $andMonthsSql = '';
        if (($args['months'] ?? null) !== null && $args['months'] !== '') {
            $monthsInterval = ((int) $args['months'] <= 1) ? 0 : ((int) $args['months'] - 1);
            $andMonthsSql = "AND am.month >= DATE_TRUNC('month', CURRENT_DATE - INTERVAL '".$monthsInterval." months')";
        }

        $andCurrencySql = '';
        $selectCurrencySql = 'cd.currency';
        if (($args['currency'] ?? null) !== null && $args['currency'] !== '') {
            $quotedCurrency = static::quoteString(mb_strtoupper((string) $args['currency']));
            $andCurrencySql = 'AND cd.currency = '.$quotedCurrency;
            $selectCurrencySql = 'COALESCE(cd.currency, '.$quotedCurrency.') as currency';
        }

        $sql = <<<'SQL'
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
            issued_invoices AS (
              SELECT
                i.id,
                i.issuing_date,
                i.total_amount_cents::float AS amount_cents,
                i.currency,
                i.billing_entity_id,
                COALESCE(COUNT(DISTINCT i.id), 0) AS invoices_count,
                COALESCE(SUM(refund_amount_cents::float),0) AS total_refund_amount_cents
              FROM invoices i
              LEFT JOIN customers c ON i.customer_id = c.id
              LEFT JOIN credit_notes cn ON cn.invoice_id = i.id
              WHERE i.organization_id = :organization_id
              AND i.self_billed IS FALSE
              AND i.status = 1
              AND i.payment_dispute_lost_at IS NULL
              {and_external_customer_id_sql}
              {and_billing_entity_id_sql}
              GROUP BY i.id, i.issuing_date, i.total_amount_cents, i.currency, i.billing_entity_id
              ORDER BY i.issuing_date ASC
            ),
            instant_charges AS (
              SELECT
                f.id,
                f.created_at AS issuing_date,
                f.amount_cents AS amount_cents,
                f.amount_currency AS currency,
                f.billing_entity_id,
                0 AS invoices_count,
                0 AS total_refund_amount_cents
              FROM fees f
              LEFT JOIN subscriptions s ON f.subscription_id = s.id
              LEFT JOIN customers c ON c.id = s.customer_id
              WHERE c.organization_id = :organization_id
              AND f.invoice_id IS NULL
              AND f.pay_in_advance IS TRUE
              {and_external_customer_id_sql}
              {and_fee_billing_entity_id_sql}
            ),
            combined_data AS (
              SELECT
                DATE_TRUNC('month', issuing_date) AS month,
                currency,
                billing_entity_id,
                COALESCE(SUM(invoices_count), 0) AS invoices_count,
                COALESCE(SUM(amount_cents), 0) AS amount_cents,
                COALESCE(SUM(total_refund_amount_cents), 0) AS total_refund_amount_cents
              FROM (
                SELECT * FROM issued_invoices
                UNION ALL
                SELECT * FROM instant_charges
              ) AS gross_revenue
              GROUP BY month, currency, billing_entity_id, total_refund_amount_cents
            )
            SELECT
              am.month,
              {select_currency_sql},
              cd.billing_entity_id,
              COALESCE(SUM(invoices_count), 0) AS invoices_count,
              SUM(cd.amount_cents - cd.total_refund_amount_cents) AS amount_cents
            FROM all_months am
            LEFT JOIN combined_data cd ON am.month = cd.month
            WHERE am.month <= DATE_TRUNC('month', CURRENT_DATE)
            {and_months_sql}
            {and_currency_sql}
            AND cd.amount_cents IS NOT NULL
            GROUP BY am.month, cd.currency, cd.billing_entity_id
            ORDER BY am.month
        SQL;

        // NOTE: the currency fragments embed their already-upcased value as
        // a quoted literal, mirroring Rails' sanitize_sql of the upcased
        // currency; the remaining :named binds are organization_id,
        // external_customer_id and billing_entity_id.
        $sql = str_replace([
            '{and_external_customer_id_sql}',
            '{and_billing_entity_id_sql}',
            '{and_fee_billing_entity_id_sql}',
            '{and_months_sql}',
            '{and_currency_sql}',
            '{select_currency_sql}',
        ], [
            $andExternalCustomerIdSql,
            $andBillingEntityIdSql,
            $andFeeBillingEntityIdSql,
            $andMonthsSql,
            $andCurrencySql,
            $selectCurrencySql,
        ], $sql);

        return static::sanitizeSql($sql, ['organization_id' => $organizationId, ...$args]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public static function cacheKey(string $organizationId, array $args = []): string
    {
        return implode('/', array_map(strval(...), [
            'gross-revenue',
            now()->format('Y-m-d'),
            $organizationId,
            $args['billing_entity_id'] ?? '',
            $args['external_customer_id'] ?? '',
            $args['currency'] ?? '',
            $args['months'] ?? '',
        ]));
    }
}
