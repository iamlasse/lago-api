<?php

declare(strict_types=1);

namespace App\Models\Analytics;

/**
 * Port of Rails' Analytics::OverdueBalance
 * (app/models/analytics/overdue_balance.rb) — invoices past their payment
 * due date, net of payments and finalized credit-note offsets, per month.
 */
class OverdueBalance extends Base
{
    /** CreditNote statuses[:finalized]. */
    private const CREDIT_NOTE_FINALIZED_STATUS = 1;

    /** Invoice STATUS[:deleted] (draft 0, finalized 1, voided 2, generating 3, failed 4, open 5, closed 6, pending 7, deleted 8). */
    private const INVOICE_DELETED_STATUS = 8;

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
        $selectCurrencySql = 'invs.currency';
        if (($args['currency'] ?? null) !== null && $args['currency'] !== '') {
            $quotedCurrency = static::quoteString(mb_strtoupper((string) $args['currency']));
            $andCurrencySql = 'AND invs.currency = '.$quotedCurrency;
            $selectCurrencySql = 'COALESCE(invs.currency, '.$quotedCurrency.') as currency';
        }

        $sql = str_replace([
            '{and_external_customer_id_sql}',
            '{and_is_customer_tin_empty_sql}',
            '{and_billing_entity_id_sql}',
            '{and_months_sql}',
            '{and_currency_sql}',
            '{select_currency_sql}',
            '{credit_note_finalized_status}',
            '{invoice_deleted_status}',
        ], [
            $andExternalCustomerIdSql,
            $andIsCustomerTinEmptySql,
            $andBillingEntityIdSql,
            $andMonthsSql,
            $andCurrencySql,
            $selectCurrencySql,
            (string) self::CREDIT_NOTE_FINALIZED_STATUS,
            (string) self::INVOICE_DELETED_STATUS,
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
            payment_overdue_invoices AS (
              SELECT
                DATE_TRUNC('month', payment_due_date) AS month,
                i.currency,
                i.billing_entity_id,
                COALESCE(SUM(
                  i.total_amount_cents -
                  i.total_paid_amount_cents -
                  COALESCE(cn.offset_amount_cents_sum, 0)
                ), 0) AS total_amount_cents,
                array_agg(DISTINCT i.id) AS ids
              FROM invoices i
              LEFT JOIN customers c ON i.customer_id = c.id
              LEFT JOIN (
                SELECT invoice_id, SUM(offset_amount_cents) AS offset_amount_cents_sum
                FROM credit_notes
                WHERE status = {credit_note_finalized_status}
                GROUP BY invoice_id
              ) cn ON cn.invoice_id = i.id
              WHERE i.organization_id = :organization_id
              AND i.self_billed IS FALSE
              AND i.payment_overdue IS TRUE
              AND i.status != {invoice_deleted_status}
              {and_external_customer_id_sql}
              {and_is_customer_tin_empty_sql}
              {and_billing_entity_id_sql}
              GROUP BY month, i.currency, i.billing_entity_id, total_amount_cents
              ORDER BY month ASC
            )
            SELECT
              am.month,
              {select_currency_sql},
              invs.billing_entity_id,
              SUM(invs.total_amount_cents) AS amount_cents,
              jsonb_agg(DISTINCT invs.ids) AS lago_invoice_ids
            FROM all_months am
            LEFT JOIN payment_overdue_invoices invs ON am.month = invs.month
            WHERE am.month <= DATE_TRUNC('month', CURRENT_DATE)
            {and_months_sql}
            {and_currency_sql}
            AND invs.total_amount_cents IS NOT NULL
            GROUP BY am.month, invs.currency, invs.billing_entity_id
            ORDER BY am.month
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
                'overdue-balance',
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
