<?php

declare(strict_types=1);

namespace App\Models\Analytics;

/**
 * Port of Rails' Analytics::InvoicedUsage
 * (app/models/analytics/invoiced_usage.rb) — charge fees net of coupons,
 * grouped by billable metric code and month. Premium-gated at the service
 * level, like Rails.
 */
class InvoicedUsage extends Base
{
    /**
     * @param  array<string, mixed>  $args  billing_entity_id, months, currency
     */
    public static function query(string $organizationId, array $args = []): string
    {
        $andBillingEntityIdSql = '';
        if (($args['billing_entity_id'] ?? null) !== null && $args['billing_entity_id'] !== '') {
            $andBillingEntityIdSql = 'AND i.billing_entity_id = :billing_entity_id';
        }

        $andMonthsSql = '';
        if (($args['months'] ?? null) !== null && $args['months'] !== '') {
            $monthsInterval = ((int) $args['months'] <= 1) ? 0 : ((int) $args['months'] - 1);
            $andMonthsSql = "AND am.month >= DATE_TRUNC('month', CURRENT_DATE - INTERVAL '".$monthsInterval." months')";
        }

        $andCurrencySql = '';
        if (($args['currency'] ?? null) !== null && $args['currency'] !== '') {
            $andCurrencySql = 'AND trpmb.currency = '.static::quoteString(mb_strtoupper((string) $args['currency']));
        }

        $sql = str_replace(
            '{and_billing_entity_id_sql}',
            $andBillingEntityIdSql,
            <<<'SQL'
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
                usage_fees AS (
                  SELECT
                    f.id,
                    f.charge_id,
                    (f.amount_cents::float - f.precise_coupons_amount_cents::float) AS amount_cents,
                    f.amount_currency AS currency,
                    f.created_at AS fee_created_at
                  FROM fees f
                  LEFT JOIN invoices i ON f.invoice_id = i.id
                  LEFT JOIN subscriptions s ON s.id = f.subscription_id
                  LEFT JOIN customers c ON c.id = s.customer_id
                  WHERE f.invoiceable_type = 'Charge'
                  AND f.fee_type = 0
                  AND i.self_billed IS FALSE
                  AND i.payment_dispute_lost_at IS NULL
                  {and_billing_entity_id_sql}
                  AND c.organization_id = :organization_id
                ),
                total_revenue_per_bm AS (
                  SELECT
                    DATE_TRUNC('month', uf.fee_created_at) AS month,
                    bm.code,
                    uf.currency,
                    COALESCE(SUM(amount_cents), 0) AS amount_cents
                  FROM usage_fees uf
                  LEFT JOIN charges c ON c.id = uf.charge_id
                  LEFT JOIN billable_metrics bm ON bm.id = c.billable_metric_id
                  GROUP BY month, bm.code, currency
                  ORDER BY month
                )
                SELECT
                  am.month,
                  trpmb.code,
                  trpmb.currency,
                  trpmb.amount_cents
                FROM all_months AS am
                LEFT JOIN total_revenue_per_bm trpmb ON trpmb.month = am.month
                WHERE am.month <= DATE_TRUNC('month', CURRENT_DATE)
                {and_months_sql}
                {and_currency_sql}
                AND trpmb.currency IS NOT NULL
                AND trpmb.amount_cents IS NOT NULL
                ORDER BY am.month DESC, trpmb.amount_cents DESC
            SQL,
        );

        $sql = str_replace('{and_months_sql}', $andMonthsSql, $sql);
        $sql = str_replace('{and_currency_sql}', $andCurrencySql, $sql);

        return static::sanitizeSql($sql, ['organization_id' => $organizationId, ...$args]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public static function cacheKey(string $organizationId, array $args = []): string
    {
        return implode('/', array_map(strval(...), [
            'invoiced-usage',
            now()->format('Y-m-d'),
            $organizationId,
            $args['billing_entity_id'] ?? '',
            $args['currency'] ?? '',
            $args['months'] ?? '',
        ]));
    }
}
