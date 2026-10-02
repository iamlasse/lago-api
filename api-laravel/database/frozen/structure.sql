
--
-- Name: btree_gin; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS btree_gin WITH SCHEMA public;


--
-- Name: btree_gist; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS btree_gist WITH SCHEMA public;


--
-- Name: pg_partman; Type: EXTENSION; Schema: -; Owner: -
--


--
-- Name: pg_trgm; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;


--
-- Name: pgcrypto; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;


--
-- Name: unaccent; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS unaccent WITH SCHEMA public;


--
-- Name: billable_metric_rounding_function; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.billable_metric_rounding_function AS ENUM (
    'round',
    'floor',
    'ceil'
);


--
-- Name: billable_metric_weighted_interval; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.billable_metric_weighted_interval AS ENUM (
    'seconds'
);


--
-- Name: billing_object_connection_behavior; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.billing_object_connection_behavior AS ENUM (
    'specific',
    'skip'
);


--
-- Name: billing_segment_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.billing_segment_status AS ENUM (
    'pending',
    'processing',
    'done',
    'failed'
);


--
-- Name: connection_category; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.connection_category AS ENUM (
    'payment',
    'tax',
    'accounting',
    'crm'
);


--
-- Name: contract_billing_time; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.contract_billing_time AS ENUM (
    'calendar',
    'anniversary'
);


--
-- Name: contract_payment_method_type; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.contract_payment_method_type AS ENUM (
    'provider',
    'manual'
);


--
-- Name: contract_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.contract_status AS ENUM (
    'pending',
    'active',
    'terminated',
    'canceled'
);


--
-- Name: customer_account_type; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.customer_account_type AS ENUM (
    'customer',
    'partner'
);


--
-- Name: customer_type; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.customer_type AS ENUM (
    'company',
    'individual'
);


--
-- Name: entitlement_privilege_value_types; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.entitlement_privilege_value_types AS ENUM (
    'integer',
    'string',
    'boolean',
    'select'
);


--
-- Name: entity_document_numbering; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.entity_document_numbering AS ENUM (
    'per_customer',
    'per_billing_entity'
);


--
-- Name: fixed_charge_charge_model; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.fixed_charge_charge_model AS ENUM (
    'standard',
    'graduated',
    'volume'
);


--
-- Name: inbound_webhook_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.inbound_webhook_status AS ENUM (
    'pending',
    'processing',
    'succeeded',
    'failed'
);


--
-- Name: invoice_custom_section_type; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.invoice_custom_section_type AS ENUM (
    'manual',
    'system_generated'
);


--
-- Name: invoice_settlement_settlement_type; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.invoice_settlement_settlement_type AS ENUM (
    'payment',
    'credit_note'
);


--
-- Name: order_execution_mode; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.order_execution_mode AS ENUM (
    'execute_in_lago',
    'order_only'
);


--
-- Name: order_form_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.order_form_status AS ENUM (
    'generated',
    'signed',
    'expired',
    'voided'
);


--
-- Name: order_form_void_reason; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.order_form_void_reason AS ENUM (
    'manual',
    'expired',
    'invalid'
);


--
-- Name: order_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.order_status AS ENUM (
    'created',
    'executed',
    'failed'
);


--
-- Name: payment_method_types; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.payment_method_types AS ENUM (
    'provider',
    'manual'
);


--
-- Name: payment_payable_payment_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.payment_payable_payment_status AS ENUM (
    'pending',
    'processing',
    'succeeded',
    'failed'
);


--
-- Name: payment_type; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.payment_type AS ENUM (
    'provider',
    'manual',
    'x402'
);


--
-- Name: product_type; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.product_type AS ENUM (
    'metered',
    'fixed'
);


--
-- Name: quote_order_type; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.quote_order_type AS ENUM (
    'subscription_creation',
    'subscription_amendment',
    'one_off'
);


--
-- Name: quote_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.quote_status AS ENUM (
    'draft',
    'approved',
    'voided'
);


--
-- Name: quote_void_reason; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.quote_void_reason AS ENUM (
    'manual',
    'superseded',
    'cascade_of_expired',
    'cascade_of_voided'
);


--
-- Name: rate_card_billing_timing; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.rate_card_billing_timing AS ENUM (
    'arrears',
    'advance'
);


--
-- Name: rate_card_rate_billing_interval_unit; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.rate_card_rate_billing_interval_unit AS ENUM (
    'day',
    'week',
    'month',
    'year'
);


--
-- Name: rate_card_rate_model; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.rate_card_rate_model AS ENUM (
    'standard',
    'graduated',
    'package',
    'percentage',
    'volume',
    'graduated_percentage',
    'custom',
    'dynamic'
);


--
-- Name: rate_card_regroup_paid_fees; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.rate_card_regroup_paid_fees AS ENUM (
    'none',
    'invoice'
);


--
-- Name: subscription_activation_rule_statuses; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.subscription_activation_rule_statuses AS ENUM (
    'inactive',
    'pending',
    'satisfied',
    'declined',
    'failed',
    'expired',
    'not_applicable'
);


--
-- Name: subscription_activation_rule_types; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.subscription_activation_rule_types AS ENUM (
    'payment'
);


--
-- Name: subscription_cancelation_reasons; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.subscription_cancelation_reasons AS ENUM (
    'payment_failed',
    'timeout'
);


--
-- Name: subscription_cancellation_reasons; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.subscription_cancellation_reasons AS ENUM (
    'payment_failed',
    'timeout',
    'manual'
);


--
-- Name: subscription_invoice_issuing_date_adjustments; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.subscription_invoice_issuing_date_adjustments AS ENUM (
    'keep_anchor',
    'align_with_finalization_date'
);


--
-- Name: subscription_invoice_issuing_date_anchors; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.subscription_invoice_issuing_date_anchors AS ENUM (
    'current_period_end',
    'next_period_start'
);


--
-- Name: subscription_invoicing_reason; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.subscription_invoicing_reason AS ENUM (
    'subscription_starting',
    'subscription_periodic',
    'subscription_terminating',
    'in_advance_charge',
    'in_advance_charge_periodic',
    'progressive_billing'
);


--
-- Name: subscription_on_termination_credit_note; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.subscription_on_termination_credit_note AS ENUM (
    'credit',
    'skip',
    'refund',
    'offset'
);


--
-- Name: subscription_on_termination_invoice; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.subscription_on_termination_invoice AS ENUM (
    'generate',
    'skip'
);


--
-- Name: tax_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.tax_status AS ENUM (
    'pending',
    'succeeded',
    'failed'
);


--
-- Name: usage_attribution_type_role; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.usage_attribution_type_role AS ENUM (
    'hierarchical',
    'flat'
);


--
-- Name: usage_monitoring_alert_direction; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.usage_monitoring_alert_direction AS ENUM (
    'increasing',
    'decreasing'
);


--
-- Name: usage_monitoring_alert_types; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.usage_monitoring_alert_types AS ENUM (
    'current_usage_amount',
    'billable_metric_current_usage_amount',
    'billable_metric_current_usage_units',
    'lifetime_usage_amount',
    'wallet_balance_amount',
    'wallet_credits_balance',
    'wallet_ongoing_balance_amount',
    'wallet_credits_ongoing_balance',
    'billable_metric_lifetime_usage_units'
);


--
-- Name: usage_monitoring_triggered_alert_kinds; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.usage_monitoring_triggered_alert_kinds AS ENUM (
    'triggered',
    'resolved',
    'seeded'
);


--
-- Name: ensure_role_consistency(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.ensure_role_consistency() RETURNS trigger
    LANGUAGE plpgsql
    AS $$ BEGIN IF OLD.organization_id IS NULL THEN RAISE EXCEPTION 'Predefined role cannot be modified'; ELSIF OLD.organization_id IS DISTINCT FROM NEW.organization_id THEN RAISE EXCEPTION 'Custom role cannot be moved to another organization'; ELSIF OLD.code IS DISTINCT FROM NEW.code THEN RAISE EXCEPTION 'The code of the role cannot be changed'; ELSIF NEW.permissions != OLD.permissions THEN NEW.permissions := ARRAY(SELECT DISTINCT unnest(NEW.permissions) ORDER BY 1); END IF; RETURN NEW; END; $$;


--
-- Name: record_deletion(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.record_deletion() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  deleted_time timestamp := clock_timestamp() AT TIME ZONE 'UTC';
BEGIN
  INSERT INTO record_deletions (organization_id, record_table, record_id, deleted_at, created_at, updated_at)
  VALUES (OLD.organization_id, TG_TABLE_NAME, OLD.id, deleted_time, deleted_time, deleted_time);

  RETURN NULL;
END;
$$;


--
-- Name: set_payment_receipt_number(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.set_payment_receipt_number() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
        DECLARE
            cust_id uuid;
            next_payment_receipt integer;
            document_number_prefix character varying;
        BEGIN
          IF NEW.number IS NULL THEN
            SELECT i.customer_id INTO cust_id
            FROM invoices i
            INNER JOIN payments p ON (p.payable_id = i.id AND p.payable_type = 'Invoice')
            WHERE p.id = NEW.payment_id;

            IF cust_id IS NULL THEN
              SELECT pr.customer_id INTO cust_id
              FROM payment_requests pr
              LEFT JOIN payments p ON (p.payable_id = pr.id AND p.payable_type = 'PaymentRequest')
              WHERE p.id = NEW.payment_id;
            END IF;

            SELECT c.slug INTO document_number_prefix
            FROM customers c
            WHERE c.id = cust_id;

            -- Atomically increment the customer's payment receipt counter and get the new value
            UPDATE customers
            SET payment_receipt_counter = payment_receipt_counter + 1
            WHERE id = cust_id
            RETURNING payment_receipt_counter INTO next_payment_receipt;

            -- Construct the payment receipt number using the customer id and the new counter value
            NEW.number := document_number_prefix || '-RCPT-' || LPAD(next_payment_receipt::text, GREATEST(6, LENGTH(next_payment_receipt::text)), '0');
          END IF;
          RETURN NEW;
        END;
        $$;


SET default_tablespace = '';

SET default_table_access_method = heap;


--
-- Name: active_storage_attachments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.active_storage_attachments (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    name character varying NOT NULL,
    record_type character varying NOT NULL,
    blob_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    record_id uuid
);


--
-- Name: active_storage_blobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.active_storage_blobs (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    key character varying NOT NULL,
    filename character varying NOT NULL,
    content_type character varying,
    metadata text,
    service_name character varying NOT NULL,
    byte_size bigint NOT NULL,
    checksum character varying,
    created_at timestamp(6) without time zone NOT NULL
);


--
-- Name: active_storage_variant_records; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.active_storage_variant_records (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    blob_id uuid NOT NULL,
    variation_digest character varying NOT NULL
);


--
-- Name: add_ons; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.add_ons (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    name character varying NOT NULL,
    code character varying NOT NULL,
    description character varying,
    amount_cents bigint NOT NULL,
    amount_currency character varying NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    deleted_at timestamp(6) without time zone,
    invoice_display_name character varying
);


--
-- Name: add_ons_taxes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.add_ons_taxes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    add_on_id uuid NOT NULL,
    tax_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    organization_id uuid NOT NULL
);


--
-- Name: adjusted_fees; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.adjusted_fees (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    fee_id uuid,
    invoice_id uuid NOT NULL,
    subscription_id uuid,
    charge_id uuid,
    invoice_display_name character varying,
    fee_type integer,
    adjusted_units boolean DEFAULT false NOT NULL,
    adjusted_amount boolean DEFAULT false NOT NULL,
    units numeric DEFAULT 0.0 NOT NULL,
    unit_amount_cents bigint DEFAULT 0 NOT NULL,
    properties jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    group_id uuid,
    grouped_by jsonb DEFAULT '{}'::jsonb NOT NULL,
    charge_filter_id uuid,
    unit_precise_amount_cents numeric(40,15) DEFAULT 0.0 NOT NULL,
    organization_id uuid NOT NULL,
    fixed_charge_id uuid
);


--
-- Name: ai_conversations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_conversations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    membership_id uuid NOT NULL,
    name character varying NOT NULL,
    mistral_conversation_id character varying,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: api_keys; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.api_keys (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    value character varying NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    expires_at timestamp(6) without time zone,
    last_used_at timestamp(6) without time zone,
    name character varying,
    permissions jsonb NOT NULL
);


--
-- Name: applied_add_ons; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.applied_add_ons (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    add_on_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    amount_cents bigint NOT NULL,
    amount_currency character varying NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: applied_coupons; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.applied_coupons (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    coupon_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    status integer DEFAULT 0 NOT NULL,
    amount_cents bigint,
    amount_currency character varying,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    terminated_at timestamp without time zone,
    percentage_rate numeric(10,5),
    frequency integer DEFAULT 0 NOT NULL,
    frequency_duration integer,
    frequency_duration_remaining integer,
    organization_id uuid NOT NULL
);


--
-- Name: applied_invoice_custom_sections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.applied_invoice_custom_sections (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    name character varying NOT NULL,
    code character varying NOT NULL,
    display_name character varying,
    details character varying,
    invoice_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    organization_id uuid NOT NULL
);


--
-- Name: applied_pricing_units; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.applied_pricing_units (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    pricing_unit_id uuid NOT NULL,
    organization_id uuid NOT NULL,
    pricing_unitable_type character varying NOT NULL,
    pricing_unitable_id uuid NOT NULL,
    conversion_rate numeric(40,15) DEFAULT 0.0 NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: applied_usage_thresholds; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.applied_usage_thresholds (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    usage_threshold_id uuid NOT NULL,
    invoice_id uuid NOT NULL,
    lifetime_usage_amount_cents bigint DEFAULT 0 NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    organization_id uuid NOT NULL
);


--
-- Name: billable_metric_filters; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.billable_metric_filters (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    billable_metric_id uuid NOT NULL,
    key character varying NOT NULL,
    "values" character varying[] DEFAULT '{}'::character varying[] NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    deleted_at timestamp(6) without time zone,
    organization_id uuid NOT NULL
);


--
-- Name: billable_metrics; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.billable_metrics (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    name character varying NOT NULL,
    code character varying NOT NULL,
    description character varying,
    properties jsonb DEFAULT '{}'::jsonb,
    aggregation_type integer NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    field_name character varying,
    deleted_at timestamp(6) without time zone,
    recurring boolean DEFAULT false NOT NULL,
    weighted_interval public.billable_metric_weighted_interval,
    custom_aggregator text,
    expression character varying,
    rounding_function public.billable_metric_rounding_function,
    rounding_precision integer
);


--
-- Name: billable_metrics_grouped_charges; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.billable_metrics_grouped_charges AS
SELECT
    NULL::uuid AS organization_id,
    NULL::character varying AS code,
    NULL::integer AS aggregation_type,
    NULL::character varying AS field_name,
    NULL::uuid AS plan_id,
    NULL::uuid AS charge_id,
    NULL::boolean AS pay_in_advance,
    NULL::jsonb AS grouped_by,
    NULL::uuid AS charge_filter_id,
    NULL::json AS filters,
    NULL::jsonb AS filters_grouped_by;


--
-- Name: billing_entities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.billing_entities (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    address_line1 character varying,
    address_line2 character varying,
    city character varying,
    country character varying,
    zipcode character varying,
    state character varying,
    timezone character varying DEFAULT 'UTC'::character varying NOT NULL,
    default_currency character varying DEFAULT 'USD'::character varying NOT NULL,
    document_locale character varying DEFAULT 'en'::character varying NOT NULL,
    document_number_prefix character varying,
    document_numbering public.entity_document_numbering DEFAULT 'per_customer'::public.entity_document_numbering NOT NULL,
    finalize_zero_amount_invoice boolean DEFAULT true NOT NULL,
    invoice_footer text,
    invoice_grace_period integer DEFAULT 0 NOT NULL,
    net_payment_term integer DEFAULT 0 NOT NULL,
    email character varying,
    email_settings character varying[] DEFAULT '{}'::character varying[] NOT NULL,
    eu_tax_management boolean DEFAULT false,
    legal_name character varying,
    legal_number character varying,
    logo character varying,
    name character varying NOT NULL,
    code character varying NOT NULL,
    tax_identification_number character varying,
    vat_rate double precision DEFAULT 0.0 NOT NULL,
    archived_at timestamp(6) without time zone,
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    applied_dunning_campaign_id uuid,
    einvoicing boolean DEFAULT false NOT NULL,
    subscription_invoice_issuing_date_anchor public.subscription_invoice_issuing_date_anchors DEFAULT 'next_period_start'::public.subscription_invoice_issuing_date_anchors NOT NULL,
    subscription_invoice_issuing_date_adjustment public.subscription_invoice_issuing_date_adjustments DEFAULT 'align_with_finalization_date'::public.subscription_invoice_issuing_date_adjustments NOT NULL,
    phone character varying,
    payment_term jsonb
);


--
-- Name: billing_entities_invoice_custom_sections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.billing_entities_invoice_custom_sections (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    billing_entity_id uuid NOT NULL,
    invoice_custom_section_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: billing_entities_taxes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.billing_entities_taxes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    billing_entity_id uuid NOT NULL,
    tax_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    organization_id uuid NOT NULL
);


--
-- Name: billing_object_connections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.billing_object_connections (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    owner_type character varying NOT NULL,
    owner_id uuid NOT NULL,
    payment_provider_customer_id uuid,
    integration_customer_id uuid,
    category public.connection_category NOT NULL,
    behavior public.billing_object_connection_behavior NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: billing_segments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.billing_segments (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    contract_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    contract_rate_card_id uuid NOT NULL,
    invoice_id uuid,
    rate_card_rate_id uuid,
    rate_override_id uuid,
    pricing_unit_id uuid,
    rate_properties jsonb DEFAULT '{}'::jsonb NOT NULL,
    currency character varying NOT NULL,
    billing_at timestamp(6) without time zone NOT NULL,
    started_at timestamp(6) without time zone NOT NULL,
    ended_at timestamp(6) without time zone NOT NULL,
    cycle_started_at timestamp(6) without time zone NOT NULL,
    proration_ratio numeric(30,10) DEFAULT 1.0 NOT NULL,
    status public.billing_segment_status DEFAULT 'pending'::public.billing_segment_status NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    CONSTRAINT billing_segments_cycle_bounds CHECK ((cycle_started_at <= started_at)),
    CONSTRAINT billing_segments_period_bounds CHECK ((started_at <= ended_at)),
    CONSTRAINT billing_segments_proration_ratio_bounds CHECK (((proration_ratio >= (0)::numeric) AND (proration_ratio <= (1)::numeric))),
    CONSTRAINT billing_segments_rate_presence CHECK (((rate_card_rate_id IS NOT NULL) OR (rate_override_id IS NOT NULL)))
);


--
-- Name: cached_aggregations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cached_aggregations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    "timestamp" timestamp(6) without time zone NOT NULL,
    external_subscription_id character varying NOT NULL,
    charge_id uuid NOT NULL,
    group_id uuid,
    current_aggregation numeric,
    max_aggregation numeric,
    max_aggregation_with_proration numeric,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    grouped_by jsonb DEFAULT '{}'::jsonb NOT NULL,
    charge_filter_id uuid,
    current_amount numeric,
    event_transaction_id character varying,
    presentation_breakdowns jsonb DEFAULT '[]'::jsonb NOT NULL
);


--
-- Name: catalog_plans; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.catalog_plans (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    name character varying NOT NULL,
    code character varying NOT NULL,
    invoice_display_name character varying,
    description character varying,
    currency character varying NOT NULL,
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: charge_filter_values; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.charge_filter_values (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    charge_filter_id uuid NOT NULL,
    billable_metric_filter_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    deleted_at timestamp(6) without time zone,
    "values" character varying[] DEFAULT '{}'::character varying[] NOT NULL,
    organization_id uuid NOT NULL
);


--
-- Name: charge_filters; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.charge_filters (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    charge_id uuid NOT NULL,
    properties jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    deleted_at timestamp(6) without time zone,
    invoice_display_name character varying,
    organization_id uuid NOT NULL,
    code character varying
);


--
-- Name: charges; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.charges (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    billable_metric_id uuid,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    plan_id uuid,
    amount_currency character varying,
    charge_model integer DEFAULT 0 NOT NULL,
    properties jsonb DEFAULT '"{}"'::jsonb NOT NULL,
    deleted_at timestamp(6) without time zone,
    pay_in_advance boolean DEFAULT false NOT NULL,
    min_amount_cents bigint DEFAULT 0 NOT NULL,
    invoiceable boolean DEFAULT true NOT NULL,
    prorated boolean DEFAULT false NOT NULL,
    invoice_display_name character varying,
    regroup_paid_fees integer,
    parent_id uuid,
    organization_id uuid NOT NULL,
    code character varying NOT NULL,
    accepts_target_wallet boolean DEFAULT false NOT NULL
);


--
-- Name: charges_taxes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.charges_taxes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    charge_id uuid NOT NULL,
    tax_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    organization_id uuid NOT NULL
);


--
-- Name: commitments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.commitments (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    plan_id uuid NOT NULL,
    commitment_type integer NOT NULL,
    amount_cents bigint NOT NULL,
    invoice_display_name character varying,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    organization_id uuid NOT NULL
);


--
-- Name: commitments_taxes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.commitments_taxes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    commitment_id uuid NOT NULL,
    tax_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    organization_id uuid NOT NULL
);


--
-- Name: contract_rate_cards; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.contract_rate_cards (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    contract_id uuid NOT NULL,
    rate_card_id uuid NOT NULL,
    billing_anchor_date date NOT NULL,
    next_billing_at timestamp without time zone,
    effective_date date NOT NULL,
    units numeric,
    deleted_at timestamp without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: contracts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.contracts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    external_id character varying NOT NULL,
    name character varying,
    status public.contract_status DEFAULT 'pending'::public.contract_status NOT NULL,
    billing_time public.contract_billing_time DEFAULT 'calendar'::public.contract_billing_time NOT NULL,
    billing_anchor_date date,
    started_at timestamp without time zone,
    ended_at timestamp without time zone,
    terminated_at timestamp without time zone,
    canceled_at timestamp without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    catalog_plan_id uuid,
    billing_entity_id uuid,
    payment_method_id uuid,
    purchase_order_number character varying,
    consolidate_invoice boolean DEFAULT true NOT NULL,
    payment_method_type public.contract_payment_method_type DEFAULT 'provider'::public.contract_payment_method_type NOT NULL
);


--
-- Name: coupon_targets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.coupon_targets (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    coupon_id uuid NOT NULL,
    plan_id uuid,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    deleted_at timestamp(6) without time zone,
    billable_metric_id uuid,
    organization_id uuid NOT NULL,
    catalog_plan_id uuid,
    CONSTRAINT coupon_targets_check_single_plan CHECK ((num_nonnulls(plan_id, catalog_plan_id) <= 1))
);


--
-- Name: coupons; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.coupons (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    name character varying NOT NULL,
    code character varying NOT NULL,
    status integer DEFAULT 0 NOT NULL,
    terminated_at timestamp(6) without time zone,
    amount_cents bigint,
    amount_currency character varying,
    expiration integer NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    coupon_type integer DEFAULT 0 NOT NULL,
    percentage_rate numeric(10,5),
    frequency integer DEFAULT 0 NOT NULL,
    frequency_duration integer,
    expiration_at timestamp(6) without time zone,
    reusable boolean DEFAULT true NOT NULL,
    limited_plans boolean DEFAULT false NOT NULL,
    deleted_at timestamp(6) without time zone,
    limited_billable_metrics boolean DEFAULT false NOT NULL,
    description text
);


--
-- Name: credit_note_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.credit_note_items (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    credit_note_id uuid NOT NULL,
    fee_id uuid,
    amount_cents bigint DEFAULT 0 NOT NULL,
    amount_currency character varying NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    precise_amount_cents numeric(30,5) NOT NULL,
    organization_id uuid NOT NULL
);


--
-- Name: credit_notes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.credit_notes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    customer_id uuid NOT NULL,
    invoice_id uuid NOT NULL,
    sequential_id integer NOT NULL,
    number character varying NOT NULL,
    credit_amount_cents bigint DEFAULT 0 NOT NULL,
    credit_amount_currency character varying NOT NULL,
    credit_status integer,
    balance_amount_cents bigint DEFAULT 0 NOT NULL,
    balance_amount_currency character varying DEFAULT '0'::character varying NOT NULL,
    reason integer NOT NULL,
    file character varying,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    total_amount_cents bigint DEFAULT 0 NOT NULL,
    total_amount_currency character varying NOT NULL,
    refund_amount_cents bigint DEFAULT 0 NOT NULL,
    refund_amount_currency character varying,
    refund_status integer,
    voided_at timestamp(6) without time zone,
    description text,
    taxes_amount_cents bigint DEFAULT 0 NOT NULL,
    refunded_at timestamp(6) without time zone,
    issuing_date date NOT NULL,
    status integer DEFAULT 1 NOT NULL,
    coupons_adjustment_amount_cents bigint DEFAULT 0 NOT NULL,
    precise_coupons_adjustment_amount_cents numeric(30,5) DEFAULT 0.0 NOT NULL,
    precise_taxes_amount_cents numeric(30,5) DEFAULT 0.0 NOT NULL,
    taxes_rate double precision DEFAULT 0.0 NOT NULL,
    organization_id uuid NOT NULL,
    xml_file character varying,
    offset_amount_cents bigint DEFAULT 0 NOT NULL,
    offset_amount_currency character varying
);


--
-- Name: credit_notes_taxes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.credit_notes_taxes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    credit_note_id uuid NOT NULL,
    tax_id uuid,
    tax_description character varying,
    tax_code character varying NOT NULL,
    tax_name character varying NOT NULL,
    tax_rate double precision DEFAULT 0.0 NOT NULL,
    amount_cents bigint DEFAULT 0 NOT NULL,
    amount_currency character varying NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    base_amount_cents bigint DEFAULT 0 NOT NULL,
    organization_id uuid NOT NULL
);


--
-- Name: credits; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.credits (
    invoice_id uuid,
    applied_coupon_id uuid,
    amount_cents bigint NOT NULL,
    amount_currency character varying NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    credit_note_id uuid,
    before_taxes boolean DEFAULT false NOT NULL,
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    progressive_billing_invoice_id uuid,
    organization_id uuid NOT NULL
);


--
-- Name: cs_admin_audit_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cs_admin_audit_logs (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    actor_user_id uuid NOT NULL,
    actor_email character varying NOT NULL,
    action integer NOT NULL,
    organization_id uuid NOT NULL,
    feature_type integer NOT NULL,
    feature_key character varying NOT NULL,
    before_value boolean,
    after_value boolean NOT NULL,
    reason text NOT NULL,
    batch_id uuid,
    rollback_of_id uuid,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: customer_metadata; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customer_metadata (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    customer_id uuid NOT NULL,
    key character varying NOT NULL,
    value character varying NOT NULL,
    display_in_invoice boolean DEFAULT false NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    organization_id uuid NOT NULL
);


--
-- Name: customers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    external_id character varying NOT NULL,
    name character varying,
    organization_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    country character varying,
    address_line1 character varying,
    address_line2 character varying,
    state character varying,
    zipcode character varying,
    email character varying,
    city character varying,
    url character varying,
    phone character varying,
    logo_url character varying,
    legal_name character varying,
    legal_number character varying,
    vat_rate double precision,
    payment_provider character varying,
    slug character varying,
    sequential_id bigint,
    currency character varying,
    invoice_grace_period integer,
    timezone character varying,
    deleted_at timestamp(6) without time zone,
    document_locale character varying,
    tax_identification_number character varying,
    net_payment_term integer,
    external_salesforce_id character varying,
    payment_provider_code character varying,
    shipping_address_line1 character varying,
    shipping_address_line2 character varying,
    shipping_city character varying,
    shipping_zipcode character varying,
    shipping_state character varying,
    shipping_country character varying,
    finalize_zero_amount_invoice integer DEFAULT 0 NOT NULL,
    firstname character varying,
    lastname character varying,
    customer_type public.customer_type,
    applied_dunning_campaign_id uuid,
    exclude_from_dunning_campaign boolean DEFAULT false NOT NULL,
    last_dunning_campaign_attempt integer DEFAULT 0 NOT NULL,
    last_dunning_campaign_attempt_at timestamp without time zone,
    skip_invoice_custom_sections boolean DEFAULT false NOT NULL,
    account_type public.customer_account_type DEFAULT 'customer'::public.customer_account_type NOT NULL,
    billing_entity_id uuid NOT NULL,
    payment_receipt_counter bigint DEFAULT 0 NOT NULL,
    subscription_invoice_issuing_date_anchor public.subscription_invoice_issuing_date_anchors,
    subscription_invoice_issuing_date_adjustment public.subscription_invoice_issuing_date_adjustments,
    awaiting_wallet_refresh boolean DEFAULT false NOT NULL,
    dunning_currency_attempts jsonb DEFAULT '{}'::jsonb NOT NULL,
    payment_term jsonb,
    CONSTRAINT check_customers_on_invoice_grace_period CHECK ((invoice_grace_period >= 0)),
    CONSTRAINT check_customers_on_net_payment_term CHECK ((net_payment_term >= 0))
);


--
-- Name: customers_invoice_custom_sections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customers_invoice_custom_sections (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    billing_entity_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    invoice_custom_section_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: customers_taxes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customers_taxes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    customer_id uuid NOT NULL,
    tax_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    organization_id uuid NOT NULL
);


--
-- Name: daily_usages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.daily_usages (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    subscription_id uuid NOT NULL,
    external_subscription_id character varying NOT NULL,
    from_datetime timestamp(6) without time zone NOT NULL,
    to_datetime timestamp(6) without time zone NOT NULL,
    usage jsonb DEFAULT '"{}"'::jsonb NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    refreshed_at timestamp(6) without time zone NOT NULL,
    usage_diff jsonb DEFAULT '"{}"'::jsonb NOT NULL,
    usage_date date
);


--
-- Name: data_export_parts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.data_export_parts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    index integer,
    data_export_id uuid NOT NULL,
    object_ids uuid[] NOT NULL,
    completed boolean DEFAULT false NOT NULL,
    csv_lines text,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    organization_id uuid NOT NULL
);


--
-- Name: data_exports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.data_exports (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    format integer,
    resource_type character varying NOT NULL,
    resource_query jsonb DEFAULT '{}'::jsonb,
    status integer DEFAULT 0 NOT NULL,
    expires_at timestamp without time zone,
    started_at timestamp without time zone,
    completed_at timestamp without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    membership_id uuid,
    organization_id uuid NOT NULL
);


--
-- Name: dunning_campaign_thresholds; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dunning_campaign_thresholds (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    dunning_campaign_id uuid NOT NULL,
    currency character varying NOT NULL,
    amount_cents bigint NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    deleted_at timestamp without time zone,
    organization_id uuid NOT NULL
);


--
-- Name: dunning_campaigns; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dunning_campaigns (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    name character varying NOT NULL,
    code character varying NOT NULL,
    description text,
    applied_to_organization boolean DEFAULT false NOT NULL,
    days_between_attempts integer DEFAULT 1 NOT NULL,
    max_attempts integer DEFAULT 1 NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    deleted_at timestamp without time zone,
    bcc_emails character varying[] DEFAULT '{}'::character varying[]
);


--
-- Name: entitlement_entitlement_values; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.entitlement_entitlement_values (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    entitlement_privilege_id uuid NOT NULL,
    entitlement_entitlement_id uuid NOT NULL,
    value character varying NOT NULL,
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: entitlement_entitlements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.entitlement_entitlements (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    entitlement_feature_id uuid NOT NULL,
    plan_id uuid,
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    subscription_id uuid,
    catalog_plan_id uuid,
    CONSTRAINT entitlement_check_exactly_one_parent CHECK ((num_nonnulls(plan_id, catalog_plan_id, subscription_id) = 1))
);


--
-- Name: entitlement_features; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.entitlement_features (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    code character varying NOT NULL,
    name character varying,
    description character varying,
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: entitlement_privileges; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.entitlement_privileges (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    entitlement_feature_id uuid NOT NULL,
    code character varying NOT NULL,
    name character varying,
    value_type public.entitlement_privilege_value_types DEFAULT 'string'::public.entitlement_privilege_value_types NOT NULL,
    config jsonb DEFAULT '{}'::jsonb NOT NULL,
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: entitlement_subscription_feature_removals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.entitlement_subscription_feature_removals (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    entitlement_feature_id uuid,
    subscription_id uuid NOT NULL,
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    entitlement_privilege_id uuid,
    CONSTRAINT check_exactly_one_feature_or_privilege_removal CHECK (((entitlement_feature_id IS NOT NULL) <> (entitlement_privilege_id IS NOT NULL)))
);


--
-- Name: error_details; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.error_details (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    owner_type character varying NOT NULL,
    owner_id uuid NOT NULL,
    organization_id uuid NOT NULL,
    details jsonb DEFAULT '{}'::jsonb NOT NULL,
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    error_code integer DEFAULT 0 NOT NULL
);


--
-- Name: events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.events (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    customer_id uuid,
    transaction_id character varying NOT NULL,
    code character varying NOT NULL,
    properties jsonb DEFAULT '{}'::jsonb NOT NULL,
    "timestamp" timestamp without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    subscription_id uuid,
    deleted_at timestamp(6) without time zone,
    external_customer_id character varying,
    external_subscription_id character varying,
    precise_total_amount_cents numeric(40,15)
)
WITH (autovacuum_vacuum_scale_factor='0.005', autovacuum_analyze_scale_factor='0', autovacuum_analyze_threshold='50000');


--
-- Name: exports_applied_coupons; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_applied_coupons AS
 SELECT ac.organization_id,
    ac.id AS lago_id,
    ac.coupon_id AS lago_coupon_id,
    ac.customer_id AS lago_customer_id,
        CASE ac.status
            WHEN 0 THEN 'active'::text
            WHEN 1 THEN 'terminated'::text
            ELSE NULL::text
        END AS status,
    ac.amount_cents,
        CASE ac.frequency
            WHEN 0 THEN NULL::bigint
            WHEN 1 THEN NULL::bigint
            ELSE
            CASE
                WHEN (cp.coupon_type = 1) THEN NULL::bigint
                ELSE (ac.amount_cents - ( SELECT (sum(cr.amount_cents))::bigint AS sum
                   FROM public.credits cr
                  WHERE (cr.applied_coupon_id = ac.id)))
            END
        END AS amount_cents_remaining,
    ac.amount_currency,
    ac.percentage_rate,
        CASE ac.frequency
            WHEN 0 THEN 'once'::text
            WHEN 1 THEN 'recurring'::text
            WHEN 2 THEN 'forever'::text
            ELSE NULL::text
        END AS frequency,
    ac.frequency_duration,
    ac.frequency_duration_remaining,
    ac.created_at,
    ac.terminated_at,
    ac.updated_at,
    ( SELECT json_agg(json_build_object('lago_id', cr.id, 'amount_cents', cr.amount_cents, 'amount_currency', cr.amount_currency, 'before_taxes', cr.before_taxes)) AS json_agg
           FROM public.credits cr
          WHERE (cr.applied_coupon_id = ac.id)) AS credits
   FROM (public.applied_coupons ac
     LEFT JOIN public.coupons cp ON ((cp.id = ac.coupon_id)));


--
-- Name: exports_billable_metrics; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_billable_metrics AS
 SELECT bm.organization_id,
    bm.id AS lago_id,
    bm.name,
    bm.code,
    bm.description,
        CASE bm.aggregation_type
            WHEN 0 THEN 'count_agg'::text
            WHEN 1 THEN 'sum_agg'::text
            WHEN 2 THEN 'max_agg'::text
            WHEN 3 THEN 'unique_count_agg'::text
            WHEN 5 THEN 'weighted_sum_agg'::text
            WHEN 6 THEN 'latest_agg'::text
            WHEN 7 THEN 'custom_agg'::text
            ELSE 'unknown'::text
        END AS aggregation_type,
    (bm.weighted_interval)::text AS weighted_interval,
    bm.recurring,
    (bm.rounding_function)::text AS rounding_function,
    bm.rounding_precision,
    bm.created_at,
    bm.updated_at,
    bm.field_name,
    bm.expression,
    COALESCE(( SELECT json_agg(json_build_object('key', bmf.key, 'values', bmf."values")) AS json_agg
           FROM public.billable_metric_filters bmf
          WHERE ((bmf.billable_metric_id = bm.id) AND (bmf.deleted_at IS NULL))), '[]'::json) AS filters
   FROM public.billable_metrics bm
  WHERE (bm.deleted_at IS NULL);


--
-- Name: exports_billing_entities; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_billing_entities AS
 SELECT be.organization_id,
    be.id AS lago_id,
    be.code,
    be.name,
    be.legal_name,
    be.legal_number,
    be.email,
    be.address_line1,
    be.address_line2,
    be.city,
    be.zipcode,
    be.state,
    be.country,
    be.vat_rate,
    be.timezone,
    be.created_at,
    be.updated_at,
    be.archived_at,
    be.deleted_at
   FROM public.billing_entities be;


--
-- Name: exports_charges; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_charges AS
 SELECT c.organization_id,
    c.id AS lago_id,
    c.billable_metric_id AS lago_billable_metric_id,
    c.plan_id AS lago_plan_id,
    c.invoice_display_name,
    c.created_at,
    c.updated_at,
    c.deleted_at,
        CASE c.charge_model
            WHEN 0 THEN 'standard'::text
            WHEN 1 THEN 'graduated'::text
            WHEN 2 THEN 'package'::text
            WHEN 3 THEN 'percentage'::text
            WHEN 4 THEN 'volume'::text
            WHEN 5 THEN 'graduated_percentage'::text
            WHEN 6 THEN 'custom'::text
            WHEN 7 THEN 'dynamic'::text
            ELSE NULL::text
        END AS charge_model,
    c.invoiceable,
        CASE c.regroup_paid_fees
            WHEN 0 THEN 'invoice'::text
            ELSE NULL::text
        END AS regroup_paid_fees,
    c.pay_in_advance,
    c.prorated,
    c.min_amount_cents,
    c.properties,
    ( SELECT json_agg(json_build_object('invoice_display_name', cf.invoice_display_name, 'properties', cf.properties, 'values', ( SELECT json_agg(json_build_object(cfcv.billable_metric_filter_id, cfcv."values")) AS json_agg
                   FROM public.charge_filter_values cfcv
                  WHERE (cfcv.charge_filter_id = cf.id)))) AS json_agg
           FROM public.charge_filters cf
          WHERE (cf.charge_id = c.id)) AS charge_filters
   FROM public.charges c;


--
-- Name: exports_coupons; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_coupons AS
 SELECT cp.organization_id,
    cp.id AS lago_id,
    cp.name,
    cp.code,
    cp.description,
        CASE cp.coupon_type
            WHEN 0 THEN 'fixed_amount'::text
            WHEN 1 THEN 'percentage'::text
            ELSE NULL::text
        END AS coupon_type,
    cp.amount_cents,
    cp.amount_currency,
    cp.percentage_rate,
        CASE cp.frequency
            WHEN 0 THEN 'once'::text
            WHEN 1 THEN 'recurring'::text
            WHEN 2 THEN 'forever'::text
            ELSE NULL::text
        END AS frequency,
    cp.frequency_duration,
    cp.reusable,
    cp.limited_plans,
    cp.limited_billable_metrics,
    to_json(ARRAY( SELECT cpt.plan_id
           FROM public.coupon_targets cpt
          WHERE ((cpt.coupon_id = cp.id) AND (cpt.plan_id IS NOT NULL)))) AS lago_plan_ids,
    to_json(ARRAY( SELECT cpt.billable_metric_id
           FROM public.coupon_targets cpt
          WHERE ((cpt.coupon_id = cp.id) AND (cpt.billable_metric_id IS NOT NULL)))) AS lago_billable_metrics_ids,
    cp.created_at,
        CASE cp.expiration
            WHEN 0 THEN 'no_expiration'::text
            WHEN 1 THEN 'time_limit'::text
            ELSE NULL::text
        END AS expiration,
    cp.expiration_at,
    cp.terminated_at,
    cp.updated_at
   FROM public.coupons cp;


--
-- Name: item_metadata; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.item_metadata (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    owner_type character varying NOT NULL,
    owner_id uuid NOT NULL,
    value jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    CONSTRAINT item_metadata_value_must_be_json_object CHECK ((jsonb_typeof(value) = 'object'::text))
);


--
-- Name: exports_credit_notes; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_credit_notes AS
 SELECT cn.organization_id,
    cn.id AS lago_id,
    cn.sequential_id,
    cn.number,
    cn.invoice_id AS lago_invoice_id,
    cn.issuing_date,
        CASE cn.credit_status
            WHEN 0 THEN 'available'::text
            WHEN 1 THEN 'consumed'::text
            WHEN 2 THEN 'voided'::text
            ELSE NULL::text
        END AS credit_status,
        CASE cn.refund_status
            WHEN 0 THEN 'pending'::text
            WHEN 1 THEN 'succeeded'::text
            WHEN 2 THEN 'failed'::text
            ELSE NULL::text
        END AS refund_status,
        CASE cn.reason
            WHEN 0 THEN 'duplicated_charge'::text
            WHEN 1 THEN 'product_unsatisfactory'::text
            WHEN 2 THEN 'order_change'::text
            WHEN 3 THEN 'order_cancellation'::text
            WHEN 4 THEN 'fraudulent_charge'::text
            WHEN 5 THEN 'other'::text
            ELSE NULL::text
        END AS reason,
    cn.description,
    cn.total_amount_currency AS currency,
    cn.total_amount_cents,
    cn.taxes_amount_cents,
    (round(((( SELECT (sum(ci.precise_amount_cents))::bigint AS sum
           FROM public.credit_note_items ci
          WHERE (ci.credit_note_id = cn.id)))::numeric - cn.precise_coupons_adjustment_amount_cents)))::bigint AS sub_total_excluding_taxes_amount_cents,
    cn.balance_amount_cents,
    cn.credit_amount_cents,
    cn.refund_amount_cents,
    cn.coupons_adjustment_amount_cents,
    cn.taxes_rate,
    cn.created_at,
    cn.updated_at,
    cn.refunded_at,
    ( SELECT json_agg(json_build_object('lago_id', ci.id, 'amount_cents', ci.amount_cents, 'amount_currency', ci.amount_currency, 'lago_fee_id', ci.fee_id)) AS json_agg
           FROM public.credit_note_items ci
          WHERE (ci.credit_note_id = cn.id)) AS items,
    ( SELECT json_agg(json_build_object('key', je.key, 'value', je.value)) AS json_agg
           FROM public.item_metadata im,
            LATERAL jsonb_each_text(im.value) je(key, value)
          WHERE (((im.owner_type)::text = 'CreditNote'::text) AND (im.owner_id = cn.id))) AS metadata,
    ( SELECT json_agg(json_build_object('lago_id', ed.id, 'error_code', ed.error_code, 'details', ed.details)) AS json_agg
           FROM public.error_details ed
          WHERE (((ed.owner_type)::text = 'CreditNote'::text) AND (ed.owner_id = cn.id))) AS error_details
   FROM public.credit_notes cn
  WHERE (cn.status <> 2);


--
-- Name: exports_credit_notes_taxes; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_credit_notes_taxes AS
 SELECT cnt.organization_id,
    cnt.id AS lago_id,
    cnt.tax_id AS lago_tax_id,
    cnt.credit_note_id AS lago_credit_note_id,
    cnt.tax_name,
    cnt.tax_code,
    cnt.tax_rate,
    cnt.tax_description,
    cnt.base_amount_cents,
    cnt.amount_cents,
    cnt.amount_currency,
    cnt.created_at,
    cnt.updated_at
   FROM public.credit_notes_taxes cnt;


--
-- Name: organizations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organizations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    name character varying NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    api_key character varying,
    webhook_url character varying,
    vat_rate double precision DEFAULT 0.0 NOT NULL,
    country character varying,
    address_line1 character varying,
    address_line2 character varying,
    state character varying,
    zipcode character varying,
    email character varying,
    city character varying,
    logo character varying,
    legal_name character varying,
    legal_number character varying,
    invoice_footer text,
    invoice_grace_period integer DEFAULT 0 NOT NULL,
    timezone character varying DEFAULT 'UTC'::character varying NOT NULL,
    document_locale character varying DEFAULT 'en'::character varying NOT NULL,
    email_settings character varying[] DEFAULT '{}'::character varying[] NOT NULL,
    tax_identification_number character varying,
    net_payment_term integer DEFAULT 0 NOT NULL,
    default_currency character varying DEFAULT 'USD'::character varying NOT NULL,
    document_numbering integer DEFAULT 0 NOT NULL,
    document_number_prefix character varying,
    eu_tax_management boolean DEFAULT false,
    premium_integrations character varying[] DEFAULT '{}'::character varying[] NOT NULL,
    custom_aggregation boolean DEFAULT false,
    finalize_zero_amount_invoice boolean DEFAULT true NOT NULL,
    clickhouse_events_store boolean DEFAULT false NOT NULL,
    hmac_key character varying NOT NULL,
    authentication_methods character varying[] DEFAULT '{email_password,google_oauth}'::character varying[] NOT NULL,
    audit_logs_period integer DEFAULT 30,
    pre_filter_events boolean DEFAULT false NOT NULL,
    clickhouse_deduplication_enabled boolean DEFAULT false NOT NULL,
    feature_flags character varying[] DEFAULT '{}'::character varying[] NOT NULL,
    max_wallets integer,
    slug character varying NOT NULL,
    CONSTRAINT check_organizations_on_invoice_grace_period CHECK ((invoice_grace_period >= 0)),
    CONSTRAINT check_organizations_on_net_payment_term CHECK ((net_payment_term >= 0))
);


--
-- Name: payment_provider_customers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.payment_provider_customers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    customer_id uuid NOT NULL,
    payment_provider_id uuid,
    type character varying NOT NULL,
    provider_customer_id character varying,
    settings jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    deleted_at timestamp(6) without time zone,
    organization_id uuid NOT NULL,
    code character varying,
    is_default boolean DEFAULT false NOT NULL
);


--
-- Name: exports_customers; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_customers AS
 SELECT c.organization_id,
    c.id AS lago_id,
    c.billing_entity_id,
    c.external_id,
    (c.account_type)::text AS account_type,
    c.name,
    c.firstname,
    c.lastname,
    (c.customer_type)::text AS customer_type,
    c.sequential_id,
    c.slug,
    c.created_at,
    c.updated_at,
    c.deleted_at,
    c.country,
    c.address_line1,
    c.address_line2,
    c.state,
    c.zipcode,
    c.email,
    c.city,
    c.url,
    c.phone,
    c.legal_name,
    c.legal_number,
    c.currency,
    c.tax_identification_number,
    c.timezone,
    COALESCE(c.timezone, o.timezone, 'UTC'::character varying) AS applicable_timezone,
    c.net_payment_term,
    c.external_salesforce_id,
        CASE c.finalize_zero_amount_invoice
            WHEN 0 THEN 'inherit'::text
            WHEN 1 THEN 'skip'::text
            WHEN 2 THEN 'finalize'::text
            ELSE NULL::text
        END AS finalize_zero_amount_invoice,
    c.skip_invoice_custom_sections,
    c.payment_provider,
    c.payment_provider_code,
    c.invoice_grace_period,
    c.vat_rate,
    COALESCE(c.invoice_grace_period, o.invoice_grace_period) AS applicable_invoice_grace_period,
    c.document_locale,
    ppc.provider_customer_id,
    ppc.settings AS provider_settings,
    '{}'::json AS metadata,
    '[]'::json AS lago_taxes_ids
   FROM ((public.customers c
     LEFT JOIN public.organizations o ON ((o.id = c.organization_id)))
     LEFT JOIN public.payment_provider_customers ppc ON (((ppc.customer_id = c.id) AND (ppc.deleted_at IS NULL) AND (ppc.payment_provider_id IS NOT NULL))));


--
-- Name: exports_daily_usages; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_daily_usages AS
 SELECT du.organization_id,
    du.id AS lago_id,
    du.from_datetime,
    du.to_datetime,
    du.refreshed_at,
    du.usage_date,
    du.usage AS daily_usage,
    du.usage_diff AS daily_usage_diff,
    du.created_at,
    du.updated_at,
    du.customer_id AS lago_customer_id,
    du.subscription_id AS lago_subscription_id,
    du.external_subscription_id
   FROM public.daily_usages du;


--
-- Name: exports_entitlement_entitlement_values; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_entitlement_entitlement_values AS
 SELECT ev.id AS lago_id,
    ev.organization_id,
    ev.entitlement_entitlement_id AS lago_entitlement_entitlement_id,
    ev.entitlement_privilege_id AS lago_entitlement_privilege_id,
    ev.value,
    ev.deleted_at,
    ev.created_at,
    ev.updated_at
   FROM public.entitlement_entitlement_values ev;


--
-- Name: exports_entitlement_entitlements; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_entitlement_entitlements AS
 SELECT ee.id AS lago_id,
    ee.organization_id,
    ee.entitlement_feature_id AS lago_entitlement_feature_id,
    ee.plan_id AS lago_plan_id,
    ee.subscription_id AS lago_subscription_id,
    ee.deleted_at,
    ee.created_at,
    ee.updated_at
   FROM public.entitlement_entitlements ee;


--
-- Name: exports_entitlement_features; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_entitlement_features AS
 SELECT ef.id AS lago_id,
    ef.organization_id,
    ef.code,
    ef.name,
    ef.description,
    ef.deleted_at,
    ef.created_at,
    ef.updated_at
   FROM public.entitlement_features ef;


--
-- Name: fees; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fees (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    invoice_id uuid,
    charge_id uuid,
    subscription_id uuid,
    amount_cents bigint NOT NULL,
    amount_currency character varying NOT NULL,
    taxes_amount_cents bigint NOT NULL,
    taxes_rate double precision DEFAULT 0.0 NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    units numeric DEFAULT 0.0 NOT NULL,
    applied_add_on_id uuid,
    properties jsonb DEFAULT '{}'::jsonb NOT NULL,
    fee_type integer,
    invoiceable_type character varying,
    invoiceable_id uuid,
    events_count integer,
    group_id uuid,
    pay_in_advance_event_id uuid,
    payment_status integer DEFAULT 0 NOT NULL,
    succeeded_at timestamp(6) without time zone,
    failed_at timestamp(6) without time zone,
    refunded_at timestamp(6) without time zone,
    true_up_parent_fee_id uuid,
    add_on_id uuid,
    description character varying,
    unit_amount_cents bigint DEFAULT 0 NOT NULL,
    pay_in_advance boolean DEFAULT false NOT NULL,
    precise_coupons_amount_cents numeric(30,5) DEFAULT 0.0 NOT NULL,
    total_aggregated_units numeric,
    invoice_display_name character varying,
    precise_unit_amount numeric(30,15) DEFAULT 0.0 NOT NULL,
    amount_details jsonb DEFAULT '{}'::jsonb NOT NULL,
    charge_filter_id uuid,
    grouped_by jsonb DEFAULT '{}'::jsonb NOT NULL,
    pay_in_advance_event_transaction_id character varying,
    deleted_at timestamp(6) without time zone,
    precise_amount_cents numeric(40,15) DEFAULT 0.0 NOT NULL,
    taxes_precise_amount_cents numeric(40,15) DEFAULT 0.0 NOT NULL,
    taxes_base_rate double precision DEFAULT 1.0 NOT NULL,
    organization_id uuid NOT NULL,
    billing_entity_id uuid NOT NULL,
    precise_credit_notes_amount_cents numeric(30,5) DEFAULT 0.0 NOT NULL,
    fixed_charge_id uuid,
    duplicated_in_advance boolean DEFAULT false,
    original_fee_id uuid,
    rate_card_rate_id uuid,
    rate_override_id uuid,
    product_filter_id uuid,
    contract_id uuid,
    contract_rate_card_id uuid,
    CONSTRAINT fees_contract_provenance_present_together CHECK (((contract_id IS NULL) = (contract_rate_card_id IS NULL)))
);


--
-- Name: fixed_charges; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fixed_charges (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    plan_id uuid NOT NULL,
    add_on_id uuid NOT NULL,
    parent_id uuid,
    charge_model public.fixed_charge_charge_model DEFAULT 'standard'::public.fixed_charge_charge_model NOT NULL,
    properties jsonb DEFAULT '{}'::jsonb NOT NULL,
    invoice_display_name character varying,
    pay_in_advance boolean DEFAULT false NOT NULL,
    prorated boolean DEFAULT false NOT NULL,
    units numeric(30,10) DEFAULT 0.0 NOT NULL,
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    code character varying NOT NULL
);


--
-- Name: invoices; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.invoices (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    issuing_date date,
    taxes_amount_cents bigint DEFAULT 0 NOT NULL,
    total_amount_cents bigint DEFAULT 0 NOT NULL,
    invoice_type integer DEFAULT 0 NOT NULL,
    payment_status integer DEFAULT 0 NOT NULL,
    number character varying DEFAULT ''::character varying NOT NULL,
    sequential_id integer,
    file character varying,
    customer_id uuid,
    taxes_rate double precision DEFAULT 0.0 NOT NULL,
    status integer DEFAULT 1 NOT NULL,
    timezone character varying DEFAULT 'UTC'::character varying NOT NULL,
    payment_attempts integer DEFAULT 0 NOT NULL,
    ready_for_payment_processing boolean DEFAULT true NOT NULL,
    organization_id uuid NOT NULL,
    version_number integer DEFAULT 4 NOT NULL,
    currency character varying,
    fees_amount_cents bigint DEFAULT 0 NOT NULL,
    coupons_amount_cents bigint DEFAULT 0 NOT NULL,
    credit_notes_amount_cents bigint DEFAULT 0 NOT NULL,
    prepaid_credit_amount_cents bigint DEFAULT 0 NOT NULL,
    sub_total_excluding_taxes_amount_cents bigint DEFAULT 0 NOT NULL,
    sub_total_including_taxes_amount_cents bigint DEFAULT 0 NOT NULL,
    payment_due_date date,
    net_payment_term integer DEFAULT 0 NOT NULL,
    voided_at timestamp(6) without time zone,
    organization_sequential_id integer DEFAULT 0 NOT NULL,
    ready_to_be_refreshed boolean DEFAULT false NOT NULL,
    payment_dispute_lost_at timestamp(6) without time zone DEFAULT NULL::timestamp without time zone,
    skip_charges boolean DEFAULT false NOT NULL,
    payment_overdue boolean DEFAULT false,
    negative_amount_cents bigint DEFAULT 0 NOT NULL,
    progressive_billing_credit_amount_cents bigint DEFAULT 0 NOT NULL,
    tax_status public.tax_status,
    total_paid_amount_cents bigint DEFAULT 0 NOT NULL,
    self_billed boolean DEFAULT false NOT NULL,
    applied_grace_period integer,
    billing_entity_id uuid NOT NULL,
    billing_entity_sequential_id integer,
    finalized_at timestamp without time zone,
    voided_invoice_id uuid,
    xml_file character varying,
    expected_finalization_date date,
    prepaid_granted_credit_amount_cents bigint,
    prepaid_purchased_credit_amount_cents bigint,
    payment_method_id uuid,
    skip_automatic_payment boolean,
    purchase_order_number character varying,
    payment_term jsonb,
    payment_term_source character varying,
    search_terms text,
    CONSTRAINT check_organizations_on_net_payment_term CHECK ((net_payment_term >= 0))
);


--
-- Name: plans; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.plans (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    name character varying NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    code character varying NOT NULL,
    "interval" integer,
    description character varying,
    amount_cents bigint,
    amount_currency character varying NOT NULL,
    trial_period double precision,
    pay_in_advance boolean DEFAULT false,
    bill_charges_monthly boolean,
    parent_id uuid,
    deleted_at timestamp(6) without time zone,
    pending_deletion boolean DEFAULT false NOT NULL,
    invoice_display_name character varying,
    bill_fixed_charges_monthly boolean DEFAULT false
);


--
-- Name: subscriptions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscriptions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    customer_id uuid NOT NULL,
    plan_id uuid NOT NULL,
    status integer NOT NULL,
    canceled_at timestamp without time zone,
    terminated_at timestamp without time zone,
    started_at timestamp without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    previous_subscription_id uuid,
    name character varying,
    external_id character varying NOT NULL,
    billing_time integer DEFAULT 0 NOT NULL,
    subscription_at timestamp(6) without time zone,
    ending_at timestamp(6) without time zone,
    trial_ended_at timestamp(6) without time zone,
    organization_id uuid NOT NULL,
    on_termination_credit_note public.subscription_on_termination_credit_note,
    on_termination_invoice public.subscription_on_termination_invoice DEFAULT 'generate'::public.subscription_on_termination_invoice NOT NULL,
    payment_method_id uuid,
    payment_method_type public.payment_method_types DEFAULT 'provider'::public.payment_method_types NOT NULL,
    skip_invoice_custom_sections boolean DEFAULT false NOT NULL,
    progressive_billing_disabled boolean DEFAULT false NOT NULL,
    last_received_event_on date,
    cancelation_reason public.subscription_cancelation_reasons,
    incompleted_at timestamp(6) without time zone,
    activated_at timestamp(6) without time zone,
    billing_entity_id uuid,
    consolidate_invoice boolean DEFAULT true NOT NULL,
    skip_daily_usage boolean DEFAULT false NOT NULL,
    cancellation_reason public.subscription_cancellation_reasons,
    purchase_order_number character varying,
    billing_anchor_date date
);


--
-- Name: exports_fees; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_fees AS
 SELECT f.organization_id,
    f.id AS lago_id,
    f.charge_id AS lago_charge_id,
    f.fixed_charge_id AS lago_fixed_charge_id,
    f.charge_filter_id AS lago_charge_filter_id,
    f.invoice_id AS lago_invoice_id,
    f.subscription_id AS lago_subscription_id,
    c.id AS lago_customer_id,
    json_build_object('type',
        CASE f.fee_type
            WHEN 0 THEN 'charge'::text
            WHEN 1 THEN 'add_on'::text
            WHEN 2 THEN 'subscription'::text
            WHEN 3 THEN 'credit'::text
            WHEN 4 THEN 'commitment'::text
            WHEN 5 THEN 'fixed_charge'::text
            ELSE 'unknown'::text
        END, 'code',
        CASE f.fee_type
            WHEN 0 THEN bm.code
            WHEN 1 THEN ao.code
            WHEN 3 THEN 'credit'::character varying
            WHEN 5 THEN fao.code
            ELSE p.code
        END, 'name',
        CASE f.fee_type
            WHEN 0 THEN bm.name
            WHEN 1 THEN ao.name
            WHEN 3 THEN 'credit'::character varying
            WHEN 5 THEN fao.name
            ELSE p.name
        END, 'description',
        CASE f.fee_type
            WHEN 0 THEN bm.description
            WHEN 1 THEN ao.description
            WHEN 3 THEN 'credit'::character varying
            WHEN 5 THEN fao.description
            ELSE p.description
        END, 'invoice_display_name', COALESCE(f.invoice_display_name,
        CASE f.fee_type
            WHEN 0 THEN COALESCE(ch.invoice_display_name, bm.name)
            WHEN 1 THEN COALESCE(ao.invoice_display_name, ao.name)
            WHEN 3 THEN 'credit'::character varying
            WHEN 5 THEN COALESCE(fc.invoice_display_name, fao.invoice_display_name, fao.name)
            ELSE p.invoice_display_name
        END), 'filters', ( SELECT json_agg(json_build_object('id', cf.id, 'charge_id', cf.charge_id, 'properties', cf.properties, 'invoice_display_name', cf.invoice_display_name)) AS json_agg
           FROM public.charge_filters cf
          WHERE (cf.charge_id = f.charge_id)), 'lago_item_id',
        CASE f.fee_type
            WHEN 0 THEN bm.id
            WHEN 1 THEN ao.id
            WHEN 3 THEN f.invoiceable_id
            WHEN 5 THEN fao.id
            ELSE f.subscription_id
        END, 'item_type',
        CASE f.fee_type
            WHEN 0 THEN 'billable_metric'::text
            WHEN 1 THEN 'add_on'::text
            WHEN 3 THEN 'wallet_transaction'::text
            WHEN 5 THEN 'add_on'::text
            ELSE 'subscription'::text
        END, 'grouped_by', f.grouped_by) AS item,
    f.pay_in_advance,
    f.amount_cents,
    ch.invoiceable,
    f.taxes_amount_cents,
    f.taxes_precise_amount_cents,
    f.taxes_rate,
    (f.amount_cents + f.taxes_amount_cents) AS total_amount_cents,
    f.amount_currency AS currency,
    f.units,
    f.description,
    f.precise_amount_cents,
    f.precise_unit_amount,
    f.precise_coupons_amount_cents,
    (f.precise_amount_cents + f.taxes_precise_amount_cents) AS precise_total_amount_cents,
    f.precise_credit_notes_amount_cents,
    f.events_count,
        CASE f.payment_status
            WHEN 0 THEN 'pending'::text
            WHEN 1 THEN 'succeeded'::text
            WHEN 2 THEN 'failed'::text
            WHEN 3 THEN 'refunded'::text
            ELSE 'unknown'::text
        END AS payment_status,
    f.created_at,
    f.succeeded_at,
    f.failed_at,
    f.refunded_at,
    f.amount_details,
    f.updated_at,
        CASE f.fee_type
            WHEN 0 THEN (((f.properties ->> 'charges_from_datetime'::text))::timestamp with time zone)::text
            WHEN 5 THEN (((f.properties ->> 'fixed_charges_from_datetime'::text))::timestamp with time zone)::text
            ELSE (((f.properties ->> 'from_datetime'::text))::timestamp with time zone)::text
        END AS from_date,
        CASE f.fee_type
            WHEN 0 THEN (((f.properties ->> 'charges_to_datetime'::text))::timestamp with time zone)::text
            WHEN 5 THEN (((f.properties ->> 'fixed_charges_to_datetime'::text))::timestamp with time zone)::text
            ELSE (((f.properties ->> 'to_datetime'::text))::timestamp with time zone)::text
        END AS to_date
   FROM (((((((((public.fees f
     LEFT JOIN public.subscriptions s ON ((f.subscription_id = s.id)))
     LEFT JOIN public.customers c ON ((s.customer_id = c.id)))
     LEFT JOIN public.charges ch ON ((f.charge_id = ch.id)))
     LEFT JOIN public.billable_metrics bm ON ((ch.billable_metric_id = bm.id)))
     LEFT JOIN public.add_ons ao ON ((f.add_on_id = ao.id)))
     LEFT JOIN public.fixed_charges fc ON ((f.fixed_charge_id = fc.id)))
     LEFT JOIN public.add_ons fao ON ((fc.add_on_id = fao.id)))
     LEFT JOIN public.plans p ON ((s.plan_id = p.id)))
     LEFT JOIN public.invoices i ON ((i.id = f.invoice_id)))
  WHERE (i.status IS DISTINCT FROM 8);


--
-- Name: fees_taxes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fees_taxes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    fee_id uuid NOT NULL,
    tax_id uuid,
    tax_description character varying,
    tax_code character varying NOT NULL,
    tax_name character varying NOT NULL,
    tax_rate double precision DEFAULT 0.0 NOT NULL,
    amount_cents bigint DEFAULT 0 NOT NULL,
    amount_currency character varying NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    precise_amount_cents numeric(40,15) DEFAULT 0.0 NOT NULL,
    organization_id uuid NOT NULL
);


--
-- Name: exports_fees_taxes; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_fees_taxes AS
 SELECT ft.organization_id,
    ft.id AS lago_id,
    ft.fee_id AS lago_fee_id,
    ft.tax_id AS lago_tax_id,
    ft.tax_name,
    ft.tax_code,
    ft.tax_rate,
    ft.tax_description,
    ft.amount_cents,
    ft.amount_currency,
    ft.created_at,
    ft.updated_at
   FROM public.fees_taxes ft;


--
-- Name: integration_customers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.integration_customers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    integration_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    external_customer_id character varying,
    type character varying NOT NULL,
    settings jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    organization_id uuid NOT NULL,
    code character varying,
    is_default boolean DEFAULT false NOT NULL,
    category public.connection_category
);


--
-- Name: exports_integration_customers; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_integration_customers AS
 SELECT ic.id AS lago_id,
    ic.organization_id,
    ic.customer_id AS lago_customer_id,
    ic.integration_id AS lago_integration_id,
    ic.external_customer_id,
    ic.type,
    ic.settings,
    ic.created_at,
    ic.updated_at
   FROM public.integration_customers ic;


--
-- Name: invoice_settlements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.invoice_settlements (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    billing_entity_id uuid NOT NULL,
    target_invoice_id uuid NOT NULL,
    settlement_type public.invoice_settlement_settlement_type NOT NULL,
    source_payment_id uuid,
    source_credit_note_id uuid,
    amount_cents bigint NOT NULL,
    amount_currency character varying NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: exports_invoice_settlements; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_invoice_settlements AS
 SELECT ins.id AS lago_id,
    ins.organization_id,
    ins.billing_entity_id AS lago_billing_entity_id,
    ins.target_invoice_id AS lago_target_invoice_id,
    (ins.settlement_type)::text AS settlement_type,
    ins.source_payment_id AS lago_source_payment_id,
    ins.source_credit_note_id AS lago_source_credit_note_id,
    ins.amount_cents,
    ins.amount_currency,
    ins.created_at,
    ins.updated_at
   FROM public.invoice_settlements ins;


--
-- Name: invoice_subscriptions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.invoice_subscriptions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    invoice_id uuid NOT NULL,
    subscription_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    recurring boolean,
    "timestamp" timestamp(6) without time zone,
    from_datetime timestamp(6) without time zone,
    to_datetime timestamp(6) without time zone,
    charges_from_datetime timestamp(6) without time zone,
    charges_to_datetime timestamp(6) without time zone,
    invoicing_reason public.subscription_invoicing_reason,
    organization_id uuid NOT NULL,
    regenerated_invoice_id uuid,
    fixed_charges_from_datetime timestamp(6) without time zone,
    fixed_charges_to_datetime timestamp(6) without time zone
);


--
-- Name: exports_invoice_subscriptions; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_invoice_subscriptions AS
 SELECT ins.organization_id,
    ins.id AS lago_id,
    ins.invoice_id AS lago_invoice_id,
    ins.regenerated_invoice_id AS lago_regenerated_invoice_id,
    ins.subscription_id AS lago_subscription_id,
    ins.created_at,
    ins.updated_at,
    ins.from_datetime,
    ins.to_datetime,
    ins.charges_from_datetime,
    ins.charges_to_datetime,
    ins."timestamp",
    (ins.invoicing_reason)::text AS invoicing_reason
   FROM (public.invoice_subscriptions ins
     JOIN public.invoices i ON ((i.id = ins.invoice_id)))
  WHERE (i.status <> 8);


--
-- Name: invoice_metadata; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.invoice_metadata (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    invoice_id uuid NOT NULL,
    key character varying NOT NULL,
    value character varying NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    organization_id uuid NOT NULL
);


--
-- Name: exports_invoices; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_invoices AS
 SELECT i.organization_id,
    i.id AS lago_id,
    i.sequential_id,
    i.customer_id,
    i.number,
    (i.issuing_date)::timestamp with time zone AS issuing_date,
    (i.payment_due_date)::timestamp with time zone AS payment_due_date,
    i.net_payment_term,
        CASE i.invoice_type
            WHEN 0 THEN 'subscription'::text
            WHEN 1 THEN 'add_on'::text
            WHEN 2 THEN 'credit'::text
            WHEN 3 THEN 'one_off'::text
            WHEN 4 THEN 'advance_charges'::text
            WHEN 5 THEN 'progressive_billing'::text
            ELSE NULL::text
        END AS invoice_type,
        CASE i.status
            WHEN 0 THEN 'draft'::text
            WHEN 1 THEN 'finalized'::text
            WHEN 2 THEN 'voided'::text
            WHEN 3 THEN 'generating'::text
            WHEN 4 THEN 'failed'::text
            WHEN 5 THEN 'open'::text
            WHEN 6 THEN 'close'::text
            WHEN 7 THEN 'pending'::text
            WHEN 8 THEN 'deleted'::text
            ELSE NULL::text
        END AS status,
        CASE i.payment_status
            WHEN 0 THEN 'pending'::text
            WHEN 1 THEN 'succeeded'::text
            WHEN 2 THEN 'failed'::text
            ELSE NULL::text
        END AS payment_status,
    (i.payment_dispute_lost_at)::timestamp with time zone AS payment_dispute_lost_at,
    i.payment_overdue,
    i.currency,
    i.fees_amount_cents,
    i.taxes_amount_cents,
    i.progressive_billing_credit_amount_cents,
    i.coupons_amount_cents,
    i.credit_notes_amount_cents,
    i.sub_total_excluding_taxes_amount_cents,
    i.sub_total_including_taxes_amount_cents,
    i.total_amount_cents,
    (i.total_amount_cents - i.total_paid_amount_cents) AS total_due_amount_cents,
    i.prepaid_credit_amount_cents,
    i.prepaid_granted_credit_amount_cents,
    i.prepaid_purchased_credit_amount_cents,
    i.version_number,
    i.created_at,
    i.updated_at,
    i.voided_at,
    ( SELECT json_agg(json_build_object('lago_id', m.id, 'key', m.key, 'value', m.value, 'created_at', m.created_at)) AS json_agg
           FROM public.invoice_metadata m
          WHERE (m.invoice_id = i.id)) AS metadata,
    ( SELECT json_agg(json_build_object('lago_id', ed.id, 'error_code', ed.error_code, 'details', ed.details)) AS json_agg
           FROM public.error_details ed
          WHERE (ed.owner_id = i.id)) AS error_details
   FROM public.invoices i
  WHERE (i.status = ANY (ARRAY[0, 1, 2, 4, 7, 8]));


--
-- Name: invoices_taxes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.invoices_taxes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    invoice_id uuid NOT NULL,
    tax_id uuid,
    tax_description character varying,
    tax_code character varying NOT NULL,
    tax_name character varying NOT NULL,
    tax_rate double precision DEFAULT 0.0 NOT NULL,
    amount_cents bigint DEFAULT 0 NOT NULL,
    amount_currency character varying NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    fees_amount_cents bigint DEFAULT 0 NOT NULL,
    taxable_base_amount_cents bigint DEFAULT 0 NOT NULL,
    organization_id uuid NOT NULL
);


--
-- Name: exports_invoices_taxes; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_invoices_taxes AS
 SELECT it.organization_id,
    it.id AS lago_id,
    it.invoice_id AS lago_invoice_id,
    it.tax_id AS lago_tax_id,
    it.tax_name,
    it.tax_code,
    it.tax_rate,
    it.tax_description,
    it.amount_cents,
    it.amount_currency,
    it.fees_amount_cents,
    it.created_at,
    it.updated_at
   FROM (public.invoices_taxes it
     JOIN public.invoices i ON ((i.id = it.invoice_id)))
  WHERE (i.status <> 8);


--
-- Name: exports_item_metadata; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_item_metadata AS
 SELECT im.id AS lago_id,
    im.organization_id,
    im.owner_type,
    im.owner_id AS lago_owner_id,
    im.value,
    im.created_at,
    im.updated_at
   FROM public.item_metadata im;


--
-- Name: invoices_payment_requests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.invoices_payment_requests (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    invoice_id uuid NOT NULL,
    payment_request_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    organization_id uuid NOT NULL
);


--
-- Name: payment_requests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.payment_requests (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    customer_id uuid NOT NULL,
    amount_cents bigint DEFAULT 0 NOT NULL,
    amount_currency character varying NOT NULL,
    email character varying,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    organization_id uuid NOT NULL,
    payment_status integer DEFAULT 0 NOT NULL,
    payment_attempts integer DEFAULT 0 NOT NULL,
    ready_for_payment_processing boolean DEFAULT true NOT NULL,
    dunning_campaign_id uuid
);


--
-- Name: payments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.payments (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    invoice_id uuid,
    payment_provider_id uuid,
    payment_provider_customer_id uuid,
    amount_cents bigint NOT NULL,
    amount_currency character varying NOT NULL,
    provider_payment_id character varying,
    status character varying NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    payable_type character varying DEFAULT 'Invoice'::character varying NOT NULL,
    payable_id uuid,
    provider_payment_data jsonb DEFAULT '{}'::jsonb,
    payable_payment_status public.payment_payable_payment_status,
    payment_type public.payment_type DEFAULT 'provider'::public.payment_type NOT NULL,
    reference character varying,
    provider_payment_method_data jsonb DEFAULT '{}'::jsonb NOT NULL,
    provider_payment_method_id character varying,
    organization_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    error_code character varying,
    payment_method_id uuid
);


--
-- Name: exports_payment_requests; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_payment_requests AS
 SELECT pr.organization_id,
    pr.id AS lago_id,
    pr.customer_id AS lago_customer_id,
    pr.payment_attempts,
    pr.amount_cents,
    pr.amount_currency,
    pr.email,
    pr.ready_for_payment_processing,
        CASE pr.payment_status
            WHEN 0 THEN 'pending'::text
            WHEN 1 THEN 'succeeded'::text
            WHEN 2 THEN 'failed'::text
            ELSE NULL::text
        END AS payment_status,
    to_json(ARRAY( SELECT p.id
           FROM public.payments p
          WHERE ((p.payable_id = pr.id) AND ((p.payable_type)::text = 'PaymentRequest'::text))
          ORDER BY p.created_at)) AS payment_ids,
    to_json(ARRAY( SELECT apr.invoice_id
           FROM public.invoices_payment_requests apr
          WHERE (apr.payment_request_id = pr.id)
          ORDER BY apr.created_at)) AS invoice_ids,
    pr.created_at,
    pr.updated_at
   FROM public.payment_requests pr;


--
-- Name: exports_payments; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_payments AS
 SELECT p.organization_id,
    p.id AS lago_id,
    p.amount_cents,
    p.amount_currency,
    (p.payable_payment_status)::text AS payment_status,
    (p.payment_type)::text AS payment_type,
    p.reference,
    p.provider_payment_id AS external_payment_id,
    (p.created_at)::timestamp with time zone AS created_at,
    (p.updated_at)::timestamp with time zone AS updated_at,
        CASE
            WHEN ((p.payable_type)::text = 'Invoice'::text) THEN to_json(ARRAY[p.payable_id])
            WHEN ((p.payable_type)::text = 'PaymentRequest'::text) THEN to_json(ARRAY( SELECT ai.invoice_id
               FROM public.invoices_payment_requests ai
              WHERE (ai.payment_request_id = p.payable_id)
              ORDER BY ai.created_at))
            ELSE to_json(ARRAY[]::uuid[])
        END AS invoice_ids
   FROM public.payments p;


--
-- Name: plans_taxes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.plans_taxes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    plan_id uuid,
    tax_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    organization_id uuid NOT NULL,
    catalog_plan_id uuid,
    CONSTRAINT plans_taxes_check_exactly_one_plan CHECK ((num_nonnulls(plan_id, catalog_plan_id) = 1))
);


--
-- Name: exports_plans; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_plans AS
 SELECT p.organization_id,
    p.id AS lago_id,
    p.name,
    p.invoice_display_name,
    p.created_at,
    p.updated_at,
    p.code,
        CASE p."interval"
            WHEN 0 THEN 'weekly'::text
            WHEN 1 THEN 'monthly'::text
            WHEN 2 THEN 'yearly'::text
            WHEN 3 THEN 'quarterly'::text
            ELSE NULL::text
        END AS plan_interval,
    p.description,
    p.amount_cents,
    p.amount_currency,
    p.trial_period,
    p.pay_in_advance,
    p.bill_charges_monthly,
    p.parent_id,
    to_json(ARRAY( SELECT pt.tax_id AS lago_tax_id
           FROM public.plans_taxes pt
          WHERE (pt.plan_id = p.id))) AS lago_taxes_ids
   FROM public.plans p
  WHERE (p.deleted_at IS NULL);


--
-- Name: record_deletions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.record_deletions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    record_table character varying NOT NULL,
    record_id uuid NOT NULL,
    deleted_at timestamp(6) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    created_at timestamp(6) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp(6) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: exports_record_deletions; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_record_deletions AS
 SELECT rd.organization_id,
    rd.id AS lago_id,
    rd.record_table AS table_name,
    rd.record_id AS lago_record_id,
    rd.deleted_at,
    rd.created_at,
    rd.updated_at
   FROM public.record_deletions rd;


--
-- Name: exports_subscriptions; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_subscriptions AS
 SELECT s.organization_id,
    s.id AS lago_id,
    s.external_id,
    s.customer_id AS lago_customer_id,
    s.name,
    s.plan_id AS lago_plan_id,
        CASE s.status
            WHEN 0 THEN 'pending'::text
            WHEN 1 THEN 'active'::text
            WHEN 2 THEN 'terminated'::text
            WHEN 3 THEN 'canceled'::text
            ELSE NULL::text
        END AS status,
        CASE s.billing_time
            WHEN 0 THEN 'calendar'::text
            WHEN 1 THEN 'anniversary'::text
            ELSE NULL::text
        END AS billing_time,
    s.subscription_at,
    s.started_at,
    s.trial_ended_at,
    s.ending_at,
    s.terminated_at,
    s.canceled_at,
    s.created_at,
    s.updated_at,
    to_json(ARRAY( SELECT ns.id
           FROM public.subscriptions ns
          WHERE (ns.previous_subscription_id = s.id))) AS lago_next_subscriptions_id,
    s.previous_subscription_id AS lago_previous_subscription_id
   FROM public.subscriptions s;


--
-- Name: taxes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.taxes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    description character varying,
    code character varying NOT NULL,
    name character varying NOT NULL,
    rate double precision DEFAULT 0.0 NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    applied_to_organization boolean DEFAULT false NOT NULL,
    auto_generated boolean DEFAULT false NOT NULL,
    deleted_at timestamp(6) without time zone
);


--
-- Name: exports_taxes; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_taxes AS
 SELECT tx.organization_id,
    tx.id AS lago_id,
    tx.name,
    tx.code,
    tx.rate,
    tx.description,
    tx.applied_to_organization,
    tx.created_at,
    tx.updated_at
   FROM public.taxes tx;


--
-- Name: usage_monitoring_alert_thresholds; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.usage_monitoring_alert_thresholds (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    usage_monitoring_alert_id uuid NOT NULL,
    value numeric(30,5) NOT NULL,
    code character varying,
    recurring boolean DEFAULT false NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    notify_on character varying[] DEFAULT '{triggered}'::character varying[] NOT NULL
);


--
-- Name: exports_usage_monitoring_alert_thresholds; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_usage_monitoring_alert_thresholds AS
 SELECT ath.id AS lago_id,
    ath.organization_id,
    ath.usage_monitoring_alert_id AS lago_alert_id,
    ath.value,
    ath.code,
    ath.recurring,
    ath.created_at,
    ath.updated_at
   FROM public.usage_monitoring_alert_thresholds ath;


--
-- Name: usage_monitoring_triggered_alerts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.usage_monitoring_triggered_alerts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    usage_monitoring_alert_id uuid NOT NULL,
    subscription_id uuid,
    current_value numeric(30,5) NOT NULL,
    previous_value numeric(30,5) NOT NULL,
    crossed_thresholds jsonb DEFAULT '{}'::jsonb,
    triggered_at timestamp(6) without time zone NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    wallet_id uuid,
    kind public.usage_monitoring_triggered_alert_kinds DEFAULT 'triggered'::public.usage_monitoring_triggered_alert_kinds NOT NULL,
    in_alarm_thresholds jsonb,
    fully_resolved boolean,
    CONSTRAINT chk_triggered_alerts_subscription_xor_wallet CHECK (((subscription_id IS NOT NULL) <> (wallet_id IS NOT NULL)))
);


--
-- Name: exports_usage_monitoring_triggered_alerts; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_usage_monitoring_triggered_alerts AS
 SELECT ta.id AS lago_id,
    ta.organization_id,
    ta.usage_monitoring_alert_id AS lago_alert_id,
    ta.subscription_id AS lago_subscription_id,
    ta.wallet_id AS lago_wallet_id,
    ta.current_value,
    ta.previous_value,
    ta.crossed_thresholds,
    ta.triggered_at,
    ta.created_at,
    ta.updated_at
   FROM public.usage_monitoring_triggered_alerts ta
  WHERE (ta.kind = 'triggered'::public.usage_monitoring_triggered_alert_kinds);


--
-- Name: usage_thresholds; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.usage_thresholds (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    plan_id uuid,
    threshold_display_name character varying,
    amount_cents bigint NOT NULL,
    recurring boolean DEFAULT false NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    deleted_at timestamp(6) without time zone,
    organization_id uuid NOT NULL,
    subscription_id uuid,
    CONSTRAINT usage_thresholds_check_exactly_one_parent CHECK (((plan_id IS NOT NULL) <> (subscription_id IS NOT NULL)))
);


--
-- Name: exports_usage_thresholds; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_usage_thresholds AS
 SELECT ut.organization_id,
    ut.plan_id AS lago_plan_id,
    ut.id AS lago_id,
    ut.amount_cents,
    ut.recurring,
    ut.threshold_display_name,
    ut.created_at,
    ut.updated_at,
    ut.deleted_at
   FROM public.usage_thresholds ut;


--
-- Name: wallet_transaction_consumptions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.wallet_transaction_consumptions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    inbound_wallet_transaction_id uuid NOT NULL,
    outbound_wallet_transaction_id uuid NOT NULL,
    consumed_amount_cents bigint NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: exports_wallet_transaction_consumptions; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_wallet_transaction_consumptions AS
 SELECT wtc.id AS lago_id,
    wtc.organization_id,
    wtc.inbound_wallet_transaction_id AS lago_inbound_wallet_transaction_id,
    wtc.outbound_wallet_transaction_id AS lago_outbound_wallet_transaction_id,
    wtc.consumed_amount_cents,
    wtc.created_at,
    wtc.updated_at
   FROM public.wallet_transaction_consumptions wtc;


--
-- Name: wallet_transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.wallet_transactions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    wallet_id uuid NOT NULL,
    transaction_type integer NOT NULL,
    status integer NOT NULL,
    amount numeric(30,5) DEFAULT 0.0 NOT NULL,
    credit_amount numeric(30,5) DEFAULT 0.0 NOT NULL,
    settled_at timestamp without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    invoice_id uuid,
    source integer DEFAULT 0 NOT NULL,
    transaction_status integer DEFAULT 0 NOT NULL,
    invoice_requires_successful_payment boolean DEFAULT false NOT NULL,
    metadata jsonb DEFAULT '[]'::jsonb,
    credit_note_id uuid,
    failed_at timestamp(6) without time zone,
    organization_id uuid NOT NULL,
    lock_version integer DEFAULT 0 NOT NULL,
    priority integer DEFAULT 50 NOT NULL,
    name character varying(255),
    payment_method_id uuid,
    payment_method_type public.payment_method_types DEFAULT 'provider'::public.payment_method_types NOT NULL,
    skip_invoice_custom_sections boolean DEFAULT false NOT NULL,
    remaining_amount_cents bigint,
    voided_invoice_id uuid,
    billing_entity_id uuid,
    purchase_order_number character varying,
    CONSTRAINT remaining_amount_cents_non_negative CHECK (((remaining_amount_cents >= 0) OR (remaining_amount_cents IS NULL)))
);


--
-- Name: exports_wallet_transactions; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_wallet_transactions AS
 SELECT wt.organization_id,
    wt.id AS lago_id,
    wt.wallet_id AS lago_wallet_id,
        CASE wt.status
            WHEN 0 THEN 'pending'::text
            WHEN 1 THEN 'settled'::text
            WHEN 2 THEN 'failed'::text
            ELSE NULL::text
        END AS status,
        CASE wt.source
            WHEN 0 THEN 'manual'::text
            WHEN 1 THEN 'interval'::text
            WHEN 2 THEN 'threshold'::text
            ELSE NULL::text
        END AS source,
        CASE wt.transaction_status
            WHEN 0 THEN 'purchased'::text
            WHEN 1 THEN 'granted'::text
            WHEN 2 THEN 'voided'::text
            WHEN 3 THEN 'invoiced'::text
            ELSE NULL::text
        END AS transaction_status,
        CASE wt.transaction_type
            WHEN 0 THEN 'inbound'::text
            WHEN 1 THEN 'outbound'::text
            ELSE NULL::text
        END AS transaction_type,
    wt.amount,
    wt.credit_amount,
    wt.settled_at,
    wt.failed_at,
    wt.created_at,
    wt.updated_at,
    wt.invoice_requires_successful_payment,
    wt.metadata,
    wt.invoice_id AS lago_invoice_id
   FROM public.wallet_transactions wt;


--
-- Name: wallets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.wallets (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    customer_id uuid NOT NULL,
    status integer NOT NULL,
    name character varying,
    rate_amount numeric(30,5) DEFAULT 0.0 NOT NULL,
    credits_balance numeric(30,5) DEFAULT 0.0 NOT NULL,
    consumed_credits numeric(30,5) DEFAULT 0.0 NOT NULL,
    expiration_at timestamp without time zone,
    last_balance_sync_at timestamp without time zone,
    last_consumed_credit_at timestamp without time zone,
    terminated_at timestamp without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    balance_cents bigint DEFAULT 0 NOT NULL,
    balance_currency character varying NOT NULL,
    consumed_amount_cents bigint DEFAULT 0 NOT NULL,
    consumed_amount_currency character varying NOT NULL,
    ongoing_balance_cents bigint DEFAULT 0 NOT NULL,
    ongoing_usage_balance_cents bigint DEFAULT 0 NOT NULL,
    credits_ongoing_balance numeric(30,5) DEFAULT 0.0 NOT NULL,
    credits_ongoing_usage_balance numeric(30,5) DEFAULT 0.0 NOT NULL,
    depleted_ongoing_balance boolean DEFAULT false NOT NULL,
    invoice_requires_successful_payment boolean DEFAULT false NOT NULL,
    lock_version integer DEFAULT 0 NOT NULL,
    ready_to_be_refreshed boolean DEFAULT false NOT NULL,
    organization_id uuid NOT NULL,
    allowed_fee_types character varying[] DEFAULT '{}'::character varying[] NOT NULL,
    last_ongoing_balance_sync_at timestamp without time zone,
    priority integer DEFAULT 50 NOT NULL,
    paid_top_up_min_amount_cents bigint,
    paid_top_up_max_amount_cents bigint,
    payment_method_id uuid,
    payment_method_type public.payment_method_types DEFAULT 'provider'::public.payment_method_types NOT NULL,
    skip_invoice_custom_sections boolean DEFAULT false NOT NULL,
    traceable boolean DEFAULT false NOT NULL,
    code character varying,
    billing_entity_id uuid,
    purchase_order_number character varying
);


--
-- Name: exports_wallets; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.exports_wallets AS
 SELECT w.organization_id,
    w.id AS lago_id,
    w.customer_id AS lago_customer_id,
        CASE w.status
            WHEN 0 THEN 'active'::text
            WHEN 1 THEN 'terminated'::text
            ELSE NULL::text
        END AS status,
    w.balance_currency AS currency,
    w.name,
    w.rate_amount,
    w.credits_balance,
    w.credits_ongoing_balance,
    w.credits_ongoing_usage_balance,
    w.balance_cents,
    w.ongoing_balance_cents,
    w.ongoing_usage_balance_cents,
    w.consumed_credits,
    w.created_at,
    w.updated_at,
    w.terminated_at,
    w.last_balance_sync_at,
    w.last_consumed_credit_at,
    w.invoice_requires_successful_payment
   FROM public.wallets w;


--
-- Name: fixed_charge_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fixed_charge_events (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    subscription_id uuid NOT NULL,
    fixed_charge_id uuid NOT NULL,
    units numeric(30,10) DEFAULT 0.0 NOT NULL,
    "timestamp" timestamp(6) without time zone,
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: fixed_charges_taxes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fixed_charges_taxes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    fixed_charge_id uuid NOT NULL,
    tax_id uuid NOT NULL,
    organization_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: flat_filters; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.flat_filters AS
SELECT
    NULL::uuid AS organization_id,
    NULL::character varying AS billable_metric_code,
    NULL::uuid AS plan_id,
    NULL::uuid AS charge_id,
    NULL::timestamp(6) without time zone AS charge_updated_at,
    NULL::uuid AS charge_filter_id,
    NULL::timestamp(6) without time zone AS charge_filter_updated_at,
    NULL::jsonb AS filters,
    NULL::jsonb AS pricing_group_keys,
    NULL::boolean AS pay_in_advance,
    NULL::boolean AS accepts_target_wallet;


--
-- Name: group_properties; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.group_properties (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    charge_id uuid NOT NULL,
    group_id uuid NOT NULL,
    "values" jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    deleted_at timestamp(6) without time zone,
    invoice_display_name character varying
);


--
-- Name: groups; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.groups (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    billable_metric_id uuid NOT NULL,
    parent_group_id uuid,
    key character varying NOT NULL,
    value character varying NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    deleted_at timestamp(6) without time zone
);


--
-- Name: idempotency_records; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.idempotency_records (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    idempotency_key bytea NOT NULL,
    resource_id uuid,
    resource_type character varying,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    organization_id uuid
);


--
-- Name: inbound_webhooks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.inbound_webhooks (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    source character varying NOT NULL,
    event_type character varying NOT NULL,
    payload jsonb NOT NULL,
    status public.inbound_webhook_status DEFAULT 'pending'::public.inbound_webhook_status NOT NULL,
    organization_id uuid NOT NULL,
    code character varying,
    signature character varying,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    processing_at timestamp without time zone
);


--
-- Name: integration_collection_mappings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.integration_collection_mappings (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    integration_id uuid NOT NULL,
    mapping_type integer NOT NULL,
    type character varying NOT NULL,
    settings jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    organization_id uuid NOT NULL,
    billing_entity_id uuid
);


--
-- Name: integration_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.integration_items (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    integration_id uuid NOT NULL,
    item_type integer NOT NULL,
    external_id character varying NOT NULL,
    external_account_code character varying,
    external_name character varying,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    organization_id uuid NOT NULL
);


--
-- Name: integration_mappings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.integration_mappings (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    integration_id uuid NOT NULL,
    mappable_type character varying NOT NULL,
    mappable_id uuid NOT NULL,
    type character varying NOT NULL,
    settings jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    organization_id uuid NOT NULL,
    billing_entity_id uuid
);


--
-- Name: integration_resources; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.integration_resources (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    syncable_type character varying NOT NULL,
    syncable_id uuid NOT NULL,
    external_id character varying,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    integration_id uuid,
    resource_type integer DEFAULT 0 NOT NULL,
    organization_id uuid NOT NULL
);


--
-- Name: integrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.integrations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    name character varying NOT NULL,
    code character varying NOT NULL,
    type character varying NOT NULL,
    secrets character varying,
    settings jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: invites; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.invites (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    membership_id uuid,
    email character varying NOT NULL,
    token character varying NOT NULL,
    status integer DEFAULT 0 NOT NULL,
    accepted_at timestamp(6) without time zone,
    revoked_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    roles character varying[] DEFAULT '{}'::character varying[] NOT NULL
);


--
-- Name: invoice_connections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.invoice_connections (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    invoice_id uuid NOT NULL,
    payment_provider_customer_id uuid,
    integration_customer_id uuid,
    category public.connection_category NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: invoice_custom_sections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.invoice_custom_sections (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    name character varying NOT NULL,
    code character varying NOT NULL,
    description character varying,
    display_name character varying,
    details character varying,
    organization_id uuid NOT NULL,
    deleted_at timestamp without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    section_type public.invoice_custom_section_type DEFAULT 'manual'::public.invoice_custom_section_type NOT NULL
);


--
-- Name: last_hour_events_mv; Type: MATERIALIZED VIEW; Schema: public; Owner: -
--

CREATE MATERIALIZED VIEW public.last_hour_events_mv AS
 WITH billable_metric_filters AS (
         SELECT billable_metrics_1.organization_id AS bm_organization_id,
            billable_metrics_1.id AS bm_id,
            billable_metrics_1.code AS bm_code,
            filters.key AS filter_key,
            filters."values" AS filter_values
           FROM (public.billable_metrics billable_metrics_1
             JOIN public.billable_metric_filters filters ON ((filters.billable_metric_id = billable_metrics_1.id)))
          WHERE ((billable_metrics_1.deleted_at IS NULL) AND (filters.deleted_at IS NULL))
        )
 SELECT events.organization_id,
    events.transaction_id,
    events.properties,
    billable_metrics.code AS billable_metric_code,
    (billable_metrics.aggregation_type <> 0) AS field_name_mandatory,
    (billable_metrics.aggregation_type = ANY (ARRAY[1, 2, 5, 6])) AS numeric_field_mandatory,
    (events.properties ->> (billable_metrics.field_name)::text) AS field_value,
    ((events.properties ->> (billable_metrics.field_name)::text) ~ '^-?\d+(\.\d+)?$'::text) AS is_numeric_field_value,
    (events.properties ? (billable_metric_filters.filter_key)::text) AS has_filter_keys,
    ((events.properties ->> (billable_metric_filters.filter_key)::text) = ANY ((billable_metric_filters.filter_values)::text[])) AS has_valid_filter_values
   FROM ((public.events
     LEFT JOIN public.billable_metrics ON ((((billable_metrics.code)::text = (events.code)::text) AND (events.organization_id = billable_metrics.organization_id))))
     LEFT JOIN billable_metric_filters ON ((billable_metrics.id = billable_metric_filters.bm_id)))
  WHERE ((events.deleted_at IS NULL) AND (events.created_at >= (date_trunc('hour'::text, now()) - '01:00:00'::interval)) AND (events.created_at < date_trunc('hour'::text, now())) AND (billable_metrics.deleted_at IS NULL))
  WITH NO DATA;


--
-- Name: lifetime_usages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lifetime_usages (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    subscription_id uuid NOT NULL,
    current_usage_amount_cents bigint DEFAULT 0 NOT NULL,
    invoiced_usage_amount_cents bigint DEFAULT 0 NOT NULL,
    recalculate_current_usage boolean DEFAULT false NOT NULL,
    recalculate_invoiced_usage boolean DEFAULT false NOT NULL,
    current_usage_amount_refreshed_at timestamp without time zone,
    invoiced_usage_amount_refreshed_at timestamp without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    deleted_at timestamp(6) without time zone,
    historical_usage_amount_cents bigint DEFAULT 0 NOT NULL
);


--
-- Name: membership_roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.membership_roles (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    membership_id uuid NOT NULL,
    role_id uuid NOT NULL,
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: memberships; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.memberships (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    user_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    status integer DEFAULT 0 NOT NULL,
    revoked_at timestamp(6) without time zone
);


--
-- Name: order_forms; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.order_forms (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    quote_version_id uuid NOT NULL,
    number character varying NOT NULL,
    sequential_id integer NOT NULL,
    status public.order_form_status DEFAULT 'generated'::public.order_form_status NOT NULL,
    void_reason public.order_form_void_reason,
    expires_at timestamp(6) without time zone,
    signed_at timestamp(6) without time zone,
    voided_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    CONSTRAINT order_forms_constraint_sequential_id_positive CHECK ((sequential_id > 0))
);


--
-- Name: orders; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.orders (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    order_form_id uuid NOT NULL,
    number character varying NOT NULL,
    sequential_id integer NOT NULL,
    status public.order_status DEFAULT 'created'::public.order_status NOT NULL,
    execution_mode public.order_execution_mode,
    execute_at timestamp(6) without time zone,
    executed_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    execution_record jsonb DEFAULT '{}'::jsonb NOT NULL,
    CONSTRAINT orders_constraint_sequential_id_positive CHECK ((sequential_id > 0))
);


--
-- Name: password_resets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_resets (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    user_id uuid NOT NULL,
    token character varying NOT NULL,
    expire_at timestamp(6) without time zone NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: payment_intents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.payment_intents (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    invoice_id uuid NOT NULL,
    organization_id uuid NOT NULL,
    payment_url character varying,
    status integer DEFAULT 0 NOT NULL,
    expires_at timestamp(6) without time zone NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    provider_session_id character varying
);


--
-- Name: payment_methods; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.payment_methods (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    payment_provider_id uuid,
    payment_provider_customer_id uuid,
    provider_method_id character varying NOT NULL,
    provider_method_type character varying,
    is_default boolean DEFAULT false NOT NULL,
    deleted_at timestamp(6) without time zone,
    details jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: payment_providers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.payment_providers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    type character varying NOT NULL,
    secrets character varying,
    settings jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    code character varying NOT NULL,
    name character varying NOT NULL,
    deleted_at timestamp(6) without time zone
);


--
-- Name: payment_receipts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.payment_receipts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    number character varying NOT NULL,
    payment_id uuid NOT NULL,
    organization_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    billing_entity_id uuid NOT NULL
);


--
-- Name: pending_vies_checks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pending_vies_checks (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    billing_entity_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    attempts_count integer DEFAULT 0 NOT NULL,
    last_attempt_at timestamp(6) without time zone,
    tax_identification_number character varying,
    last_error_type character varying,
    last_error_message text,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: plan_rate_cards; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.plan_rate_cards (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    rate_card_id uuid NOT NULL,
    units numeric,
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    catalog_plan_id uuid
);


--
-- Name: presentation_breakdowns; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.presentation_breakdowns (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    fee_id uuid NOT NULL,
    presentation_by jsonb DEFAULT '[]'::jsonb NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    units numeric DEFAULT 0.0 NOT NULL
);


--
-- Name: pricing_unit_usages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pricing_unit_usages (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    fee_id uuid NOT NULL,
    pricing_unit_id uuid NOT NULL,
    short_name character varying NOT NULL,
    amount_cents bigint NOT NULL,
    precise_amount_cents numeric(40,15) DEFAULT 0.0 NOT NULL,
    unit_amount_cents bigint DEFAULT 0 NOT NULL,
    conversion_rate numeric(40,15) DEFAULT 0.0 NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    precise_unit_amount numeric(30,15) DEFAULT 0.0 NOT NULL
);


--
-- Name: pricing_units; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pricing_units (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    name character varying NOT NULL,
    code character varying NOT NULL,
    short_name character varying NOT NULL,
    description text,
    organization_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: product_categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_categories (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    code character varying NOT NULL,
    name character varying NOT NULL,
    description character varying,
    invoice_display_name character varying,
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: product_filter_values; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_filter_values (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    product_filter_id uuid NOT NULL,
    billable_metric_filter_id uuid NOT NULL,
    value character varying,
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: product_filters; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_filters (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    product_id uuid NOT NULL,
    name character varying NOT NULL,
    code character varying NOT NULL,
    description character varying,
    invoice_display_name character varying,
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: products; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.products (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    product_category_id uuid,
    billable_metric_id uuid,
    add_on_id uuid,
    charge_id uuid,
    product_type public.product_type NOT NULL,
    code character varying NOT NULL,
    name character varying NOT NULL,
    invoice_display_name character varying,
    description text,
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: quantified_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.quantified_events (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    external_subscription_id character varying NOT NULL,
    external_id character varying,
    added_at timestamp(6) without time zone NOT NULL,
    removed_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    billable_metric_id uuid,
    properties jsonb DEFAULT '{}'::jsonb NOT NULL,
    deleted_at timestamp(6) without time zone,
    group_id uuid,
    organization_id uuid NOT NULL,
    grouped_by jsonb DEFAULT '{}'::jsonb NOT NULL,
    charge_filter_id uuid
);


--
-- Name: quote_owners; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.quote_owners (
    id bigint NOT NULL,
    organization_id uuid NOT NULL,
    quote_id uuid NOT NULL,
    user_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: quote_owners_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.quote_owners_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: quote_owners_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.quote_owners_id_seq OWNED BY public.quote_owners.id;


--
-- Name: quote_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.quote_versions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    quote_id uuid NOT NULL,
    sequential_id integer NOT NULL,
    status public.quote_status DEFAULT 'draft'::public.quote_status NOT NULL,
    approved_at timestamp(6) without time zone,
    voided_at timestamp(6) without time zone,
    void_reason public.quote_void_reason,
    billing_items jsonb,
    content text,
    share_token character varying,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    currency character varying,
    mention_variables jsonb,
    billing_entity_id uuid,
    CONSTRAINT quote_versions_constraint_approved_at_matches_status CHECK (((status = 'approved'::public.quote_status) = (approved_at IS NOT NULL))),
    CONSTRAINT quote_versions_constraint_sequential_id_positive CHECK ((sequential_id > 0)),
    CONSTRAINT quote_versions_constraint_void_fields_match_status CHECK (((status = 'voided'::public.quote_status) = ((void_reason IS NOT NULL) AND (voided_at IS NOT NULL))))
);


--
-- Name: quotes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.quotes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    subscription_id uuid,
    number character varying NOT NULL,
    sequential_id integer NOT NULL,
    order_type public.quote_order_type NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    CONSTRAINT quotes_constraint_sequential_id_positive CHECK ((sequential_id > 0))
);


--
-- Name: rate_card_rates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rate_card_rates (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    rate_card_id uuid NOT NULL,
    code character varying NOT NULL,
    effective_from timestamp(6) without time zone NOT NULL,
    rate_model public.rate_card_rate_model NOT NULL,
    rate_properties jsonb DEFAULT '{}'::jsonb NOT NULL,
    min_amount_cents bigint DEFAULT 0 NOT NULL,
    billing_interval_count integer DEFAULT 1 NOT NULL,
    billing_interval_unit public.rate_card_rate_billing_interval_unit NOT NULL,
    applied_pricing_unit_conversion_rate numeric(30,10),
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    CONSTRAINT rate_card_rates_billing_interval_count_positive CHECK ((billing_interval_count >= 1))
);


--
-- Name: rate_cards; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rate_cards (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    product_id uuid NOT NULL,
    product_filter_id uuid,
    code character varying NOT NULL,
    name character varying NOT NULL,
    description character varying,
    currency character varying NOT NULL,
    billing_timing public.rate_card_billing_timing DEFAULT 'arrears'::public.rate_card_billing_timing NOT NULL,
    proration boolean DEFAULT false NOT NULL,
    display_on_invoice boolean DEFAULT true NOT NULL,
    regroup_paid_fees public.rate_card_regroup_paid_fees,
    applied_pricing_unit_code character varying,
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: rate_cards_taxes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rate_cards_taxes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    rate_card_id uuid NOT NULL,
    tax_id uuid NOT NULL,
    organization_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: rate_overrides; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rate_overrides (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    rate_model public.rate_card_rate_model NOT NULL,
    rate_properties jsonb DEFAULT '{}'::jsonb NOT NULL,
    min_amount_cents bigint DEFAULT 0 NOT NULL,
    billing_interval_count integer,
    billing_interval_unit public.rate_card_rate_billing_interval_unit,
    pricing_unit_conversion_rate numeric(30,10),
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: rate_phases; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rate_phases (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    plan_rate_card_id uuid,
    contract_rate_card_id uuid,
    code character varying NOT NULL,
    "position" integer NOT NULL,
    billing_interval_cycle_count integer,
    rate_override_id uuid,
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    name character varying,
    CONSTRAINT rate_phases_exactly_one_parent CHECK (((plan_rate_card_id IS NULL) <> (contract_rate_card_id IS NULL)))
);


--
-- Name: recurring_transaction_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.recurring_transaction_rules (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    wallet_id uuid NOT NULL,
    trigger integer DEFAULT 0 NOT NULL,
    paid_credits numeric(30,5) DEFAULT 0.0 NOT NULL,
    granted_credits numeric(30,5) DEFAULT 0.0 NOT NULL,
    threshold_credits numeric(30,5) DEFAULT 0.0,
    "interval" integer DEFAULT 0,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    method integer DEFAULT 0 NOT NULL,
    target_ongoing_balance numeric(30,5),
    started_at timestamp(6) without time zone,
    invoice_requires_successful_payment boolean DEFAULT false NOT NULL,
    transaction_metadata jsonb DEFAULT '[]'::jsonb,
    expiration_at timestamp(6) without time zone,
    terminated_at timestamp(6) without time zone,
    status integer DEFAULT 0,
    organization_id uuid NOT NULL,
    ignore_paid_top_up_limits boolean DEFAULT false NOT NULL,
    transaction_name character varying(255),
    payment_method_id uuid,
    payment_method_type public.payment_method_types DEFAULT 'provider'::public.payment_method_types NOT NULL,
    skip_invoice_custom_sections boolean DEFAULT false NOT NULL,
    grants_target_top_up boolean,
    purchase_order_number character varying
);


--
-- Name: recurring_transaction_rules_invoice_custom_sections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.recurring_transaction_rules_invoice_custom_sections (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    recurring_transaction_rule_id uuid NOT NULL,
    invoice_custom_section_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: refunds; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.refunds (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    payment_id uuid NOT NULL,
    credit_note_id uuid,
    payment_provider_id uuid,
    payment_provider_customer_id uuid NOT NULL,
    amount_cents bigint DEFAULT 0 NOT NULL,
    amount_currency character varying NOT NULL,
    status character varying NOT NULL,
    provider_refund_id character varying NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    organization_id uuid NOT NULL,
    refundable_type character varying,
    refundable_id uuid,
    reason character varying,
    CONSTRAINT refunds_credit_note_or_refundable_present CHECK (((credit_note_id IS NOT NULL) OR ((refundable_type IS NOT NULL) AND (refundable_id IS NOT NULL))))
);


--
-- Name: roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.roles (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid,
    code character varying NOT NULL,
    admin boolean DEFAULT false NOT NULL,
    permissions character varying[] DEFAULT '{}'::character varying[] NOT NULL,
    name character varying NOT NULL,
    description character varying,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    deleted_at timestamp(6) without time zone,
    CONSTRAINT code_is_valid CHECK (((code)::text ~ '^[a-z0-9_]{1,100}$'::text)),
    CONSTRAINT custom_role_should_have_permissions CHECK (((organization_id IS NULL) OR (cardinality(permissions) > 0))),
    CONSTRAINT description_max_length CHECK ((length((description)::text) <= 255)),
    CONSTRAINT name_is_valid CHECK (((name)::text ~ '^.{1,100}$'::text)),
    CONSTRAINT permissions_has_no_empty_parts CHECK (((NOT ((permissions)::text ~ '([\{,]:|::|:[,\}])'::text)) AND (NOT (''::text = ANY ((permissions)::text[]))))),
    CONSTRAINT predefined_role_cannot_have_permissions CHECK (((organization_id IS NOT NULL) OR (cardinality(permissions) = 0)))
);


--
-- Name: streaming_destinations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.streaming_destinations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    type character varying NOT NULL,
    event_types character varying[] DEFAULT '{}'::character varying[] NOT NULL,
    active boolean DEFAULT false NOT NULL,
    settings jsonb DEFAULT '{}'::jsonb NOT NULL,
    secrets character varying,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: subscription_activation_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscription_activation_rules (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    subscription_id uuid NOT NULL,
    type public.subscription_activation_rule_types NOT NULL,
    timeout_hours integer DEFAULT 0 NOT NULL,
    status public.subscription_activation_rule_statuses DEFAULT 'inactive'::public.subscription_activation_rule_statuses NOT NULL,
    expires_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: subscription_fixed_charge_units_overrides; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscription_fixed_charge_units_overrides (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    subscription_id uuid NOT NULL,
    fixed_charge_id uuid NOT NULL,
    units numeric(30,10) DEFAULT 0.0 NOT NULL,
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    CONSTRAINT sub_fc_units_overrides_units_non_negative CHECK ((units >= (0)::numeric))
);


--
-- Name: subscriptions_invoice_custom_sections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscriptions_invoice_custom_sections (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    subscription_id uuid NOT NULL,
    invoice_custom_section_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: usage_attribution_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.usage_attribution_types (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    parent_id uuid,
    code character varying NOT NULL,
    name character varying,
    role public.usage_attribution_type_role NOT NULL,
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    attribution_keys character varying[] DEFAULT '{}'::character varying[] NOT NULL,
    description character varying
);


--
-- Name: usage_attribution_values; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.usage_attribution_values (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    usage_attribution_type_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    parent_id uuid,
    value character varying NOT NULL,
    last_seen_at timestamp(6) without time zone,
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: usage_monitoring_alerts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.usage_monitoring_alerts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    subscription_external_id character varying,
    billable_metric_id uuid,
    alert_type public.usage_monitoring_alert_types NOT NULL,
    previous_value numeric(30,5) DEFAULT 0.0 NOT NULL,
    last_processed_at timestamp(6) without time zone,
    name character varying,
    code character varying NOT NULL,
    deleted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    wallet_id uuid,
    direction public.usage_monitoring_alert_direction DEFAULT 'increasing'::public.usage_monitoring_alert_direction NOT NULL,
    CONSTRAINT chk_alerts_subscription_xor_wallet CHECK (((subscription_external_id IS NOT NULL) <> (wallet_id IS NOT NULL)))
);


--
-- Name: usage_monitoring_subscription_activities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.usage_monitoring_subscription_activities (
    id bigint NOT NULL,
    organization_id uuid NOT NULL,
    subscription_id uuid NOT NULL,
    enqueued boolean DEFAULT false NOT NULL,
    inserted_at timestamp(6) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    enqueued_at timestamp(6) without time zone
);


--
-- Name: usage_monitoring_subscription_activities_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.usage_monitoring_subscription_activities_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: usage_monitoring_subscription_activities_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.usage_monitoring_subscription_activities_id_seq OWNED BY public.usage_monitoring_subscription_activities.id;


--
-- Name: user_devices; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_devices (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    user_id uuid NOT NULL,
    fingerprint character varying NOT NULL,
    browser character varying,
    os character varying,
    device_type character varying,
    last_logged_at timestamp(6) without time zone NOT NULL,
    last_ip_address character varying,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    email character varying,
    password_digest character varying,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    cs_admin boolean DEFAULT false NOT NULL
);


--
-- Name: versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.versions (
    id bigint NOT NULL,
    item_type character varying NOT NULL,
    item_id character varying NOT NULL,
    event character varying NOT NULL,
    whodunnit character varying,
    object jsonb,
    object_changes jsonb,
    created_at timestamp(6) without time zone,
    lago_version character varying
);


--
-- Name: versions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.versions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: versions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.versions_id_seq OWNED BY public.versions.id;


--
-- Name: wallet_targets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.wallet_targets (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    wallet_id uuid NOT NULL,
    billable_metric_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: wallet_transactions_invoice_custom_sections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.wallet_transactions_invoice_custom_sections (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    wallet_transaction_id uuid NOT NULL,
    invoice_custom_section_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: wallets_invoice_custom_sections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.wallets_invoice_custom_sections (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    wallet_id uuid NOT NULL,
    invoice_custom_section_id uuid NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: webhook_endpoints; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.webhook_endpoints (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    organization_id uuid NOT NULL,
    webhook_url character varying NOT NULL,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    signature_algo integer DEFAULT 0 NOT NULL,
    event_types character varying[],
    name character varying
);


--
-- Name: webhooks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.webhooks (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    object_id uuid,
    object_type character varying,
    status integer DEFAULT 0 NOT NULL,
    retries integer DEFAULT 0 NOT NULL,
    http_status integer,
    endpoint character varying,
    webhook_type character varying,
    payload json,
    response json,
    last_retried_at timestamp without time zone,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    webhook_endpoint_id uuid,
    organization_id uuid NOT NULL,
    payload_key character varying,
    response_key character varying
);


--
-- Name: quote_owners id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quote_owners ALTER COLUMN id SET DEFAULT nextval('public.quote_owners_id_seq'::regclass);


--
-- Name: usage_monitoring_subscription_activities id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_monitoring_subscription_activities ALTER COLUMN id SET DEFAULT nextval('public.usage_monitoring_subscription_activities_id_seq'::regclass);


--
-- Name: versions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.versions ALTER COLUMN id SET DEFAULT nextval('public.versions_id_seq'::regclass);


--
-- Name: active_storage_attachments active_storage_attachments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.active_storage_attachments
    ADD CONSTRAINT active_storage_attachments_pkey PRIMARY KEY (id);


--
-- Name: active_storage_blobs active_storage_blobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.active_storage_blobs
    ADD CONSTRAINT active_storage_blobs_pkey PRIMARY KEY (id);


--
-- Name: active_storage_variant_records active_storage_variant_records_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.active_storage_variant_records
    ADD CONSTRAINT active_storage_variant_records_pkey PRIMARY KEY (id);


--
-- Name: add_ons add_ons_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.add_ons
    ADD CONSTRAINT add_ons_pkey PRIMARY KEY (id);


--
-- Name: add_ons_taxes add_ons_taxes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.add_ons_taxes
    ADD CONSTRAINT add_ons_taxes_pkey PRIMARY KEY (id);


--
-- Name: adjusted_fees adjusted_fees_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.adjusted_fees
    ADD CONSTRAINT adjusted_fees_pkey PRIMARY KEY (id);


--
-- Name: ai_conversations ai_conversations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_conversations
    ADD CONSTRAINT ai_conversations_pkey PRIMARY KEY (id);


--
-- Name: api_keys api_keys_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_keys
    ADD CONSTRAINT api_keys_pkey PRIMARY KEY (id);


--
-- Name: applied_add_ons applied_add_ons_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.applied_add_ons
    ADD CONSTRAINT applied_add_ons_pkey PRIMARY KEY (id);


--
-- Name: applied_coupons applied_coupons_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.applied_coupons
    ADD CONSTRAINT applied_coupons_pkey PRIMARY KEY (id);


--
-- Name: applied_invoice_custom_sections applied_invoice_custom_sections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.applied_invoice_custom_sections
    ADD CONSTRAINT applied_invoice_custom_sections_pkey PRIMARY KEY (id);


--
-- Name: applied_pricing_units applied_pricing_units_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.applied_pricing_units
    ADD CONSTRAINT applied_pricing_units_pkey PRIMARY KEY (id);


--
-- Name: applied_usage_thresholds applied_usage_thresholds_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.applied_usage_thresholds
    ADD CONSTRAINT applied_usage_thresholds_pkey PRIMARY KEY (id);


--
-- Name: billable_metric_filters billable_metric_filters_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billable_metric_filters
    ADD CONSTRAINT billable_metric_filters_pkey PRIMARY KEY (id);


--
-- Name: billable_metrics billable_metrics_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billable_metrics
    ADD CONSTRAINT billable_metrics_pkey PRIMARY KEY (id);


--
-- Name: billing_entities_invoice_custom_sections billing_entities_invoice_custom_sections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_entities_invoice_custom_sections
    ADD CONSTRAINT billing_entities_invoice_custom_sections_pkey PRIMARY KEY (id);


--
-- Name: billing_entities billing_entities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_entities
    ADD CONSTRAINT billing_entities_pkey PRIMARY KEY (id);


--
-- Name: billing_entities_taxes billing_entities_taxes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_entities_taxes
    ADD CONSTRAINT billing_entities_taxes_pkey PRIMARY KEY (id);


--
-- Name: billing_object_connections billing_object_connections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_object_connections
    ADD CONSTRAINT billing_object_connections_pkey PRIMARY KEY (id);


--
-- Name: billing_segments billing_segments_no_overlapping_periods; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_segments
    ADD CONSTRAINT billing_segments_no_overlapping_periods EXCLUDE USING gist (organization_id WITH =, contract_id WITH =, customer_id WITH =, contract_rate_card_id WITH =, tsrange(started_at, ended_at, '[]'::text) WITH &&);


--
-- Name: billing_segments billing_segments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_segments
    ADD CONSTRAINT billing_segments_pkey PRIMARY KEY (id);


--
-- Name: cached_aggregations cached_aggregations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cached_aggregations
    ADD CONSTRAINT cached_aggregations_pkey PRIMARY KEY (id);


--
-- Name: catalog_plans catalog_plans_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.catalog_plans
    ADD CONSTRAINT catalog_plans_pkey PRIMARY KEY (id);


--
-- Name: charge_filter_values charge_filter_values_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.charge_filter_values
    ADD CONSTRAINT charge_filter_values_pkey PRIMARY KEY (id);


--
-- Name: charge_filters charge_filters_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.charge_filters
    ADD CONSTRAINT charge_filters_pkey PRIMARY KEY (id);


--
-- Name: charges charges_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.charges
    ADD CONSTRAINT charges_pkey PRIMARY KEY (id);


--
-- Name: charges_taxes charges_taxes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.charges_taxes
    ADD CONSTRAINT charges_taxes_pkey PRIMARY KEY (id);


--
-- Name: commitments commitments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.commitments
    ADD CONSTRAINT commitments_pkey PRIMARY KEY (id);


--
-- Name: commitments_taxes commitments_taxes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.commitments_taxes
    ADD CONSTRAINT commitments_taxes_pkey PRIMARY KEY (id);


--
-- Name: contract_rate_cards contract_rate_cards_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contract_rate_cards
    ADD CONSTRAINT contract_rate_cards_pkey PRIMARY KEY (id);


--
-- Name: contracts contracts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contracts
    ADD CONSTRAINT contracts_pkey PRIMARY KEY (id);


--
-- Name: coupon_targets coupon_targets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coupon_targets
    ADD CONSTRAINT coupon_targets_pkey PRIMARY KEY (id);


--
-- Name: coupons coupons_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coupons
    ADD CONSTRAINT coupons_pkey PRIMARY KEY (id);


--
-- Name: credit_note_items credit_note_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.credit_note_items
    ADD CONSTRAINT credit_note_items_pkey PRIMARY KEY (id);


--
-- Name: credit_notes credit_notes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.credit_notes
    ADD CONSTRAINT credit_notes_pkey PRIMARY KEY (id);


--
-- Name: credit_notes_taxes credit_notes_taxes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.credit_notes_taxes
    ADD CONSTRAINT credit_notes_taxes_pkey PRIMARY KEY (id);


--
-- Name: credits credits_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.credits
    ADD CONSTRAINT credits_pkey PRIMARY KEY (id);


--
-- Name: cs_admin_audit_logs cs_admin_audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cs_admin_audit_logs
    ADD CONSTRAINT cs_admin_audit_logs_pkey PRIMARY KEY (id);


--
-- Name: customer_metadata customer_metadata_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_metadata
    ADD CONSTRAINT customer_metadata_pkey PRIMARY KEY (id);


--
-- Name: customers_invoice_custom_sections customers_invoice_custom_sections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers_invoice_custom_sections
    ADD CONSTRAINT customers_invoice_custom_sections_pkey PRIMARY KEY (id);


--
-- Name: customers customers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_pkey PRIMARY KEY (id);


--
-- Name: customers_taxes customers_taxes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers_taxes
    ADD CONSTRAINT customers_taxes_pkey PRIMARY KEY (id);


--
-- Name: daily_usages daily_usages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.daily_usages
    ADD CONSTRAINT daily_usages_pkey PRIMARY KEY (id);


--
-- Name: data_export_parts data_export_parts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.data_export_parts
    ADD CONSTRAINT data_export_parts_pkey PRIMARY KEY (id);


--
-- Name: data_exports data_exports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.data_exports
    ADD CONSTRAINT data_exports_pkey PRIMARY KEY (id);


--
-- Name: dunning_campaign_thresholds dunning_campaign_thresholds_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dunning_campaign_thresholds
    ADD CONSTRAINT dunning_campaign_thresholds_pkey PRIMARY KEY (id);


--
-- Name: dunning_campaigns dunning_campaigns_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dunning_campaigns
    ADD CONSTRAINT dunning_campaigns_pkey PRIMARY KEY (id);


--
-- Name: entitlement_entitlement_values entitlement_entitlement_values_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entitlement_entitlement_values
    ADD CONSTRAINT entitlement_entitlement_values_pkey PRIMARY KEY (id);


--
-- Name: entitlement_entitlements entitlement_entitlements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entitlement_entitlements
    ADD CONSTRAINT entitlement_entitlements_pkey PRIMARY KEY (id);


--
-- Name: entitlement_features entitlement_features_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entitlement_features
    ADD CONSTRAINT entitlement_features_pkey PRIMARY KEY (id);


--
-- Name: entitlement_privileges entitlement_privileges_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entitlement_privileges
    ADD CONSTRAINT entitlement_privileges_pkey PRIMARY KEY (id);


--
-- Name: entitlement_subscription_feature_removals entitlement_subscription_feature_removals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entitlement_subscription_feature_removals
    ADD CONSTRAINT entitlement_subscription_feature_removals_pkey PRIMARY KEY (id);


--
-- Name: error_details error_details_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.error_details
    ADD CONSTRAINT error_details_pkey PRIMARY KEY (id);


--
-- Name: events events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_pkey PRIMARY KEY (id);


--
-- Name: fees fees_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fees
    ADD CONSTRAINT fees_pkey PRIMARY KEY (id);


--
-- Name: fees_taxes fees_taxes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fees_taxes
    ADD CONSTRAINT fees_taxes_pkey PRIMARY KEY (id);


--
-- Name: fixed_charge_events fixed_charge_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fixed_charge_events
    ADD CONSTRAINT fixed_charge_events_pkey PRIMARY KEY (id);


--
-- Name: fixed_charges fixed_charges_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fixed_charges
    ADD CONSTRAINT fixed_charges_pkey PRIMARY KEY (id);


--
-- Name: fixed_charges_taxes fixed_charges_taxes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fixed_charges_taxes
    ADD CONSTRAINT fixed_charges_taxes_pkey PRIMARY KEY (id);


--
-- Name: group_properties group_properties_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.group_properties
    ADD CONSTRAINT group_properties_pkey PRIMARY KEY (id);


--
-- Name: groups groups_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.groups
    ADD CONSTRAINT groups_pkey PRIMARY KEY (id);


--
-- Name: idempotency_records idempotency_records_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.idempotency_records
    ADD CONSTRAINT idempotency_records_pkey PRIMARY KEY (id);


--
-- Name: inbound_webhooks inbound_webhooks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inbound_webhooks
    ADD CONSTRAINT inbound_webhooks_pkey PRIMARY KEY (id);


--
-- Name: integration_collection_mappings integration_collection_mappings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integration_collection_mappings
    ADD CONSTRAINT integration_collection_mappings_pkey PRIMARY KEY (id);


--
-- Name: integration_customers integration_customers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integration_customers
    ADD CONSTRAINT integration_customers_pkey PRIMARY KEY (id);


--
-- Name: integration_items integration_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integration_items
    ADD CONSTRAINT integration_items_pkey PRIMARY KEY (id);


--
-- Name: integration_mappings integration_mappings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integration_mappings
    ADD CONSTRAINT integration_mappings_pkey PRIMARY KEY (id);


--
-- Name: integration_resources integration_resources_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integration_resources
    ADD CONSTRAINT integration_resources_pkey PRIMARY KEY (id);


--
-- Name: integrations integrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integrations
    ADD CONSTRAINT integrations_pkey PRIMARY KEY (id);


--
-- Name: invites invites_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invites
    ADD CONSTRAINT invites_pkey PRIMARY KEY (id);


--
-- Name: invoice_connections invoice_connections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_connections
    ADD CONSTRAINT invoice_connections_pkey PRIMARY KEY (id);


--
-- Name: invoice_custom_sections invoice_custom_sections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_custom_sections
    ADD CONSTRAINT invoice_custom_sections_pkey PRIMARY KEY (id);


--
-- Name: invoice_metadata invoice_metadata_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_metadata
    ADD CONSTRAINT invoice_metadata_pkey PRIMARY KEY (id);


--
-- Name: invoice_settlements invoice_settlements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_settlements
    ADD CONSTRAINT invoice_settlements_pkey PRIMARY KEY (id);


--
-- Name: invoice_subscriptions invoice_subscriptions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_subscriptions
    ADD CONSTRAINT invoice_subscriptions_pkey PRIMARY KEY (id);


--
-- Name: invoices_payment_requests invoices_payment_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices_payment_requests
    ADD CONSTRAINT invoices_payment_requests_pkey PRIMARY KEY (id);


--
-- Name: invoices invoices_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_pkey PRIMARY KEY (id);


--
-- Name: invoices_taxes invoices_taxes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices_taxes
    ADD CONSTRAINT invoices_taxes_pkey PRIMARY KEY (id);


--
-- Name: item_metadata item_metadata_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.item_metadata
    ADD CONSTRAINT item_metadata_pkey PRIMARY KEY (id);


--
-- Name: lifetime_usages lifetime_usages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lifetime_usages
    ADD CONSTRAINT lifetime_usages_pkey PRIMARY KEY (id);


--
-- Name: membership_roles membership_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.membership_roles
    ADD CONSTRAINT membership_roles_pkey PRIMARY KEY (id);


--
-- Name: memberships memberships_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.memberships
    ADD CONSTRAINT memberships_pkey PRIMARY KEY (id);


--
-- Name: order_forms order_forms_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.order_forms
    ADD CONSTRAINT order_forms_pkey PRIMARY KEY (id);


--
-- Name: orders orders_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT orders_pkey PRIMARY KEY (id);


--
-- Name: organizations organizations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizations
    ADD CONSTRAINT organizations_pkey PRIMARY KEY (id);


--
-- Name: password_resets password_resets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_resets
    ADD CONSTRAINT password_resets_pkey PRIMARY KEY (id);


--
-- Name: payment_intents payment_intents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_intents
    ADD CONSTRAINT payment_intents_pkey PRIMARY KEY (id);


--
-- Name: payment_methods payment_methods_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_methods
    ADD CONSTRAINT payment_methods_pkey PRIMARY KEY (id);


--
-- Name: payment_provider_customers payment_provider_customers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_provider_customers
    ADD CONSTRAINT payment_provider_customers_pkey PRIMARY KEY (id);


--
-- Name: payment_providers payment_providers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_providers
    ADD CONSTRAINT payment_providers_pkey PRIMARY KEY (id);


--
-- Name: payment_receipts payment_receipts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_receipts
    ADD CONSTRAINT payment_receipts_pkey PRIMARY KEY (id);


--
-- Name: payment_requests payment_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_requests
    ADD CONSTRAINT payment_requests_pkey PRIMARY KEY (id);


--
-- Name: payments payments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_pkey PRIMARY KEY (id);


--
-- Name: pending_vies_checks pending_vies_checks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pending_vies_checks
    ADD CONSTRAINT pending_vies_checks_pkey PRIMARY KEY (id);


--
-- Name: plan_rate_cards plan_rate_cards_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_rate_cards
    ADD CONSTRAINT plan_rate_cards_pkey PRIMARY KEY (id);


--
-- Name: plans plans_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plans
    ADD CONSTRAINT plans_pkey PRIMARY KEY (id);


--
-- Name: plans_taxes plans_taxes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plans_taxes
    ADD CONSTRAINT plans_taxes_pkey PRIMARY KEY (id);


--
-- Name: presentation_breakdowns presentation_breakdowns_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.presentation_breakdowns
    ADD CONSTRAINT presentation_breakdowns_pkey PRIMARY KEY (id);


--
-- Name: pricing_unit_usages pricing_unit_usages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pricing_unit_usages
    ADD CONSTRAINT pricing_unit_usages_pkey PRIMARY KEY (id);


--
-- Name: pricing_units pricing_units_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pricing_units
    ADD CONSTRAINT pricing_units_pkey PRIMARY KEY (id);


--
-- Name: product_categories product_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_categories
    ADD CONSTRAINT product_categories_pkey PRIMARY KEY (id);


--
-- Name: product_filter_values product_filter_values_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_filter_values
    ADD CONSTRAINT product_filter_values_pkey PRIMARY KEY (id);


--
-- Name: product_filters product_filters_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_filters
    ADD CONSTRAINT product_filters_pkey PRIMARY KEY (id);


--
-- Name: products products_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_pkey PRIMARY KEY (id);


--
-- Name: quantified_events quantified_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quantified_events
    ADD CONSTRAINT quantified_events_pkey PRIMARY KEY (id);


--
-- Name: quote_owners quote_owners_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quote_owners
    ADD CONSTRAINT quote_owners_pkey PRIMARY KEY (id);


--
-- Name: quote_versions quote_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quote_versions
    ADD CONSTRAINT quote_versions_pkey PRIMARY KEY (id);


--
-- Name: quotes quotes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quotes
    ADD CONSTRAINT quotes_pkey PRIMARY KEY (id);


--
-- Name: rate_card_rates rate_card_rates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rate_card_rates
    ADD CONSTRAINT rate_card_rates_pkey PRIMARY KEY (id);


--
-- Name: rate_cards rate_cards_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rate_cards
    ADD CONSTRAINT rate_cards_pkey PRIMARY KEY (id);


--
-- Name: rate_cards_taxes rate_cards_taxes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rate_cards_taxes
    ADD CONSTRAINT rate_cards_taxes_pkey PRIMARY KEY (id);


--
-- Name: rate_overrides rate_overrides_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rate_overrides
    ADD CONSTRAINT rate_overrides_pkey PRIMARY KEY (id);


--
-- Name: rate_phases rate_phases_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rate_phases
    ADD CONSTRAINT rate_phases_pkey PRIMARY KEY (id);


--
-- Name: record_deletions record_deletions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.record_deletions
    ADD CONSTRAINT record_deletions_pkey PRIMARY KEY (id);


--
-- Name: recurring_transaction_rules_invoice_custom_sections recurring_transaction_rules_invoice_custom_sections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recurring_transaction_rules_invoice_custom_sections
    ADD CONSTRAINT recurring_transaction_rules_invoice_custom_sections_pkey PRIMARY KEY (id);


--
-- Name: recurring_transaction_rules recurring_transaction_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recurring_transaction_rules
    ADD CONSTRAINT recurring_transaction_rules_pkey PRIMARY KEY (id);


--
-- Name: refunds refunds_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.refunds
    ADD CONSTRAINT refunds_pkey PRIMARY KEY (id);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: streaming_destinations streaming_destinations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.streaming_destinations
    ADD CONSTRAINT streaming_destinations_pkey PRIMARY KEY (id);


--
-- Name: subscription_activation_rules subscription_activation_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_activation_rules
    ADD CONSTRAINT subscription_activation_rules_pkey PRIMARY KEY (id);


--
-- Name: subscription_fixed_charge_units_overrides subscription_fixed_charge_units_overrides_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_fixed_charge_units_overrides
    ADD CONSTRAINT subscription_fixed_charge_units_overrides_pkey PRIMARY KEY (id);


--
-- Name: subscriptions_invoice_custom_sections subscriptions_invoice_custom_sections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions_invoice_custom_sections
    ADD CONSTRAINT subscriptions_invoice_custom_sections_pkey PRIMARY KEY (id);


--
-- Name: subscriptions subscriptions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_pkey PRIMARY KEY (id);


--
-- Name: taxes taxes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.taxes
    ADD CONSTRAINT taxes_pkey PRIMARY KEY (id);


--
-- Name: usage_attribution_types usage_attribution_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_attribution_types
    ADD CONSTRAINT usage_attribution_types_pkey PRIMARY KEY (id);


--
-- Name: usage_attribution_values usage_attribution_values_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_attribution_values
    ADD CONSTRAINT usage_attribution_values_pkey PRIMARY KEY (id);


--
-- Name: usage_monitoring_alert_thresholds usage_monitoring_alert_thresholds_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_monitoring_alert_thresholds
    ADD CONSTRAINT usage_monitoring_alert_thresholds_pkey PRIMARY KEY (id);


--
-- Name: usage_monitoring_alerts usage_monitoring_alerts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_monitoring_alerts
    ADD CONSTRAINT usage_monitoring_alerts_pkey PRIMARY KEY (id);


--
-- Name: usage_monitoring_subscription_activities usage_monitoring_subscription_activities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_monitoring_subscription_activities
    ADD CONSTRAINT usage_monitoring_subscription_activities_pkey PRIMARY KEY (id);


--
-- Name: usage_monitoring_triggered_alerts usage_monitoring_triggered_alerts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_monitoring_triggered_alerts
    ADD CONSTRAINT usage_monitoring_triggered_alerts_pkey PRIMARY KEY (id);


--
-- Name: usage_thresholds usage_thresholds_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_thresholds
    ADD CONSTRAINT usage_thresholds_pkey PRIMARY KEY (id);


--
-- Name: user_devices user_devices_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_devices
    ADD CONSTRAINT user_devices_pkey PRIMARY KEY (id);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: versions versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.versions
    ADD CONSTRAINT versions_pkey PRIMARY KEY (id);


--
-- Name: wallet_targets wallet_targets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallet_targets
    ADD CONSTRAINT wallet_targets_pkey PRIMARY KEY (id);


--
-- Name: wallet_transaction_consumptions wallet_transaction_consumptions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallet_transaction_consumptions
    ADD CONSTRAINT wallet_transaction_consumptions_pkey PRIMARY KEY (id);


--
-- Name: wallet_transactions_invoice_custom_sections wallet_transactions_invoice_custom_sections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallet_transactions_invoice_custom_sections
    ADD CONSTRAINT wallet_transactions_invoice_custom_sections_pkey PRIMARY KEY (id);


--
-- Name: wallet_transactions wallet_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallet_transactions
    ADD CONSTRAINT wallet_transactions_pkey PRIMARY KEY (id);


--
-- Name: wallets_invoice_custom_sections wallets_invoice_custom_sections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallets_invoice_custom_sections
    ADD CONSTRAINT wallets_invoice_custom_sections_pkey PRIMARY KEY (id);


--
-- Name: wallets wallets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallets
    ADD CONSTRAINT wallets_pkey PRIMARY KEY (id);


--
-- Name: webhook_endpoints webhook_endpoints_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.webhook_endpoints
    ADD CONSTRAINT webhook_endpoints_pkey PRIMARY KEY (id);


--
-- Name: webhooks webhooks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.webhooks
    ADD CONSTRAINT webhooks_pkey PRIMARY KEY (id);


--
-- Name: idx_aggregation_lookup; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_aggregation_lookup ON public.cached_aggregations USING btree (external_subscription_id, charge_id, "timestamp") INCLUDE (organization_id, grouped_by);


--
-- Name: idx_alerts_code_unique_per_subscription; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_alerts_code_unique_per_subscription ON public.usage_monitoring_alerts USING btree (code, subscription_external_id, organization_id) WHERE (deleted_at IS NULL);


--
-- Name: idx_alerts_unique_per_type_per_subscription; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_alerts_unique_per_type_per_subscription ON public.usage_monitoring_alerts USING btree (subscription_external_id, organization_id, alert_type) WHERE ((billable_metric_id IS NULL) AND (deleted_at IS NULL));


--
-- Name: idx_alerts_unique_per_type_per_subscription_with_bm; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_alerts_unique_per_type_per_subscription_with_bm ON public.usage_monitoring_alerts USING btree (subscription_external_id, organization_id, alert_type, billable_metric_id) WHERE ((billable_metric_id IS NOT NULL) AND (deleted_at IS NULL));


--
-- Name: idx_alerts_unique_per_type_per_wallet; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_alerts_unique_per_type_per_wallet ON public.usage_monitoring_alerts USING btree (wallet_id, organization_id, alert_type) WHERE ((billable_metric_id IS NULL) AND (deleted_at IS NULL));


--
-- Name: idx_billable_metrics_id_agg_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_billable_metrics_id_agg_type ON public.billable_metrics USING btree (id) INCLUDE (aggregation_type);


--
-- Name: idx_cached_aggregation_filtered_lookup; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cached_aggregation_filtered_lookup ON public.cached_aggregations USING btree (organization_id, external_subscription_id, charge_id, "timestamp" DESC, created_at DESC) INCLUDE (grouped_by, charge_filter_id, event_transaction_id);


--
-- Name: idx_cs_audit_actor_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cs_audit_actor_created ON public.cs_admin_audit_logs USING btree (actor_user_id, created_at DESC);


--
-- Name: idx_cs_audit_batch; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cs_audit_batch ON public.cs_admin_audit_logs USING btree (batch_id);


--
-- Name: idx_cs_audit_feature_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cs_audit_feature_created ON public.cs_admin_audit_logs USING btree (feature_key, created_at DESC);


--
-- Name: idx_cs_audit_org_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cs_audit_org_created ON public.cs_admin_audit_logs USING btree (organization_id, created_at DESC);


--
-- Name: idx_cs_audit_unique_rollback; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_cs_audit_unique_rollback ON public.cs_admin_audit_logs USING btree (rollback_of_id) WHERE (rollback_of_id IS NOT NULL);


--
-- Name: idx_enqueued_per_organization; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_enqueued_per_organization ON public.usage_monitoring_subscription_activities USING btree (organization_id, enqueued);


--
-- Name: idx_events_billing_lookup; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_events_billing_lookup ON public.events USING btree (external_subscription_id, organization_id, code, "timestamp") INCLUDE (properties) WHERE (deleted_at IS NULL);


--
-- Name: idx_events_for_distinct_codes; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_events_for_distinct_codes ON public.events USING btree (external_subscription_id, organization_id, "timestamp") INCLUDE (code) WHERE (deleted_at IS NULL);


--
-- Name: idx_features_code_unique_per_organization; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_features_code_unique_per_organization ON public.entitlement_features USING btree (code, organization_id) WHERE (deleted_at IS NULL);


--
-- Name: idx_invoice_subscriptions_on_subscription_with_timestamps; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_invoice_subscriptions_on_subscription_with_timestamps ON public.invoice_subscriptions USING btree (subscription_id, COALESCE(to_datetime, created_at) DESC);


--
-- Name: idx_invoices_organization_id_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_invoices_organization_id_status ON public.invoices USING btree (organization_id, status);


--
-- Name: idx_on_billing_entity_id_724373e5ae; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_billing_entity_id_724373e5ae ON public.billing_entities_invoice_custom_sections USING btree (billing_entity_id);


--
-- Name: idx_on_billing_entity_id_billing_entity_sequential__bd26b2e655; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_billing_entity_id_billing_entity_sequential__bd26b2e655 ON public.invoices USING btree (billing_entity_id, billing_entity_sequential_id DESC) INCLUDE (self_billed);


--
-- Name: idx_on_billing_entity_id_customer_id_invoice_custom_e7aada65cb; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_on_billing_entity_id_customer_id_invoice_custom_e7aada65cb ON public.customers_invoice_custom_sections USING btree (billing_entity_id, customer_id, invoice_custom_section_id);


--
-- Name: idx_on_billing_entity_id_invoice_custom_section_id_bd78c547d3; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_on_billing_entity_id_invoice_custom_section_id_bd78c547d3 ON public.billing_entities_invoice_custom_sections USING btree (billing_entity_id, invoice_custom_section_id);


--
-- Name: idx_on_contract_id_billing_at_status_3588bfae7a; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_contract_id_billing_at_status_3588bfae7a ON public.billing_segments USING btree (contract_id, billing_at, status);


--
-- Name: idx_on_dunning_campaign_id_currency_fbf233b2ae; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_on_dunning_campaign_id_currency_fbf233b2ae ON public.dunning_campaign_thresholds USING btree (dunning_campaign_id, currency) WHERE (deleted_at IS NULL);


--
-- Name: idx_on_entitlement_entitlement_id_48c0b3356a; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_entitlement_entitlement_id_48c0b3356a ON public.entitlement_entitlement_values USING btree (entitlement_entitlement_id);


--
-- Name: idx_on_entitlement_feature_id_821ae72311; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_entitlement_feature_id_821ae72311 ON public.entitlement_subscription_feature_removals USING btree (entitlement_feature_id);


--
-- Name: idx_on_entitlement_privilege_id_6a228dc433; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_entitlement_privilege_id_6a228dc433 ON public.entitlement_entitlement_values USING btree (entitlement_privilege_id);


--
-- Name: idx_on_entitlement_privilege_id_9946ccf514; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_entitlement_privilege_id_9946ccf514 ON public.entitlement_subscription_feature_removals USING btree (entitlement_privilege_id);


--
-- Name: idx_on_entitlement_privilege_id_entitlement_entitle_9d0542eb1a; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_on_entitlement_privilege_id_entitlement_entitle_9d0542eb1a ON public.entitlement_entitlement_values USING btree (entitlement_privilege_id, entitlement_entitlement_id) WHERE (deleted_at IS NULL);


--
-- Name: idx_on_fixed_charge_id_06503ae1a5; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_fixed_charge_id_06503ae1a5 ON public.subscription_fixed_charge_units_overrides USING btree (fixed_charge_id);


--
-- Name: idx_on_inbound_wallet_transaction_id_e54d00758d; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_inbound_wallet_transaction_id_e54d00758d ON public.wallet_transaction_consumptions USING btree (inbound_wallet_transaction_id);


--
-- Name: idx_on_invoice_custom_section_id_50c2a2e7c0; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_invoice_custom_section_id_50c2a2e7c0 ON public.recurring_transaction_rules_invoice_custom_sections USING btree (invoice_custom_section_id);


--
-- Name: idx_on_invoice_custom_section_id_5f37496c8c; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_invoice_custom_section_id_5f37496c8c ON public.customers_invoice_custom_sections USING btree (invoice_custom_section_id);


--
-- Name: idx_on_invoice_custom_section_id_aca4661c33; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_invoice_custom_section_id_aca4661c33 ON public.wallets_invoice_custom_sections USING btree (invoice_custom_section_id);


--
-- Name: idx_on_invoice_custom_section_id_b381df5bb5; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_invoice_custom_section_id_b381df5bb5 ON public.wallet_transactions_invoice_custom_sections USING btree (invoice_custom_section_id);


--
-- Name: idx_on_invoice_custom_section_id_ccb39e9622; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_invoice_custom_section_id_ccb39e9622 ON public.billing_entities_invoice_custom_sections USING btree (invoice_custom_section_id);


--
-- Name: idx_on_invoice_custom_section_id_d8b9068730; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_invoice_custom_section_id_d8b9068730 ON public.subscriptions_invoice_custom_sections USING btree (invoice_custom_section_id);


--
-- Name: idx_on_invoice_id_payment_request_id_aa550779a4; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_on_invoice_id_payment_request_id_aa550779a4 ON public.invoices_payment_requests USING btree (invoice_id, payment_request_id);


--
-- Name: idx_on_organization_id_376a587b04; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_organization_id_376a587b04 ON public.usage_monitoring_subscription_activities USING btree (organization_id);


--
-- Name: idx_on_organization_id_7020c3c43a; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_organization_id_7020c3c43a ON public.entitlement_subscription_feature_removals USING btree (organization_id);


--
-- Name: idx_on_organization_id_83703a45f4; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_organization_id_83703a45f4 ON public.billing_entities_invoice_custom_sections USING btree (organization_id);


--
-- Name: idx_on_organization_id_ccdf05cbfe; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_organization_id_ccdf05cbfe ON public.wallet_transactions_invoice_custom_sections USING btree (organization_id);


--
-- Name: idx_on_organization_id_deleted_at_225e3f789d; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_organization_id_deleted_at_225e3f789d ON public.invoice_custom_sections USING btree (organization_id, deleted_at);


--
-- Name: idx_on_organization_id_e73219f079; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_organization_id_e73219f079 ON public.recurring_transaction_rules_invoice_custom_sections USING btree (organization_id);


--
-- Name: idx_on_organization_id_e742f77454; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_organization_id_e742f77454 ON public.subscription_fixed_charge_units_overrides USING btree (organization_id);


--
-- Name: idx_on_organization_id_external_id_gin_trgm_ops_fb8058a497; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_organization_id_external_id_gin_trgm_ops_fb8058a497 ON public.subscriptions USING gin (organization_id, external_id public.gin_trgm_ops);


--
-- Name: idx_on_organization_id_external_subscription_id_df3a30d96d; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_organization_id_external_subscription_id_df3a30d96d ON public.daily_usages USING btree (organization_id, external_subscription_id);


--
-- Name: idx_on_organization_id_organization_sequential_id_2387146f54; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_organization_id_organization_sequential_id_2387146f54 ON public.invoices USING btree (organization_id, organization_sequential_id DESC) INCLUDE (self_billed);


--
-- Name: idx_on_organization_id_provider_payment_id_gin_trgm_2bcf073c0b; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_organization_id_provider_payment_id_gin_trgm_2bcf073c0b ON public.payments USING gin (organization_id, provider_payment_id public.gin_trgm_ops);


--
-- Name: idx_on_organization_id_subscription_at_created_at_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_organization_id_subscription_at_created_at_id ON public.subscriptions USING btree (organization_id, subscription_at DESC NULLS LAST, created_at DESC, id);


--
-- Name: idx_on_outbound_wallet_transaction_id_cf6ff733c6; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_outbound_wallet_transaction_id_cf6ff733c6 ON public.wallet_transaction_consumptions USING btree (outbound_wallet_transaction_id);


--
-- Name: idx_on_owner_type_owner_id_category_937f7f9880; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_on_owner_type_owner_id_category_937f7f9880 ON public.billing_object_connections USING btree (owner_type, owner_id, category);


--
-- Name: idx_on_payment_provider_customer_id_ed5e6793bd; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_payment_provider_customer_id_ed5e6793bd ON public.billing_object_connections USING btree (payment_provider_customer_id);


--
-- Name: idx_on_plan_id_billable_metric_id_pay_in_advance_4a205974cb; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_plan_id_billable_metric_id_pay_in_advance_4a205974cb ON public.charges USING btree (plan_id, billable_metric_id, pay_in_advance) WHERE (deleted_at IS NULL);


--
-- Name: idx_on_recurring_transaction_rule_id_fba3d39cca; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_recurring_transaction_rule_id_fba3d39cca ON public.recurring_transaction_rules_invoice_custom_sections USING btree (recurring_transaction_rule_id);


--
-- Name: idx_on_subscription_id_295edd8bb3; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_subscription_id_295edd8bb3 ON public.entitlement_subscription_feature_removals USING btree (subscription_id);


--
-- Name: idx_on_subscription_id_bd763c5aa3; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_subscription_id_bd763c5aa3 ON public.subscription_fixed_charge_units_overrides USING btree (subscription_id);


--
-- Name: idx_on_subscription_id_type_8feb7b9623; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_on_subscription_id_type_8feb7b9623 ON public.subscription_activation_rules USING btree (subscription_id, type);


--
-- Name: idx_on_usage_monitoring_alert_id_4290c95dec; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_usage_monitoring_alert_id_4290c95dec ON public.usage_monitoring_triggered_alerts USING btree (usage_monitoring_alert_id);


--
-- Name: idx_on_usage_monitoring_alert_id_78eb24d06c; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_usage_monitoring_alert_id_78eb24d06c ON public.usage_monitoring_alert_thresholds USING btree (usage_monitoring_alert_id);


--
-- Name: idx_on_usage_monitoring_alert_id_recurring_756a2a370d; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_on_usage_monitoring_alert_id_recurring_756a2a370d ON public.usage_monitoring_alert_thresholds USING btree (usage_monitoring_alert_id, recurring) WHERE (recurring IS TRUE);


--
-- Name: idx_on_usage_threshold_id_invoice_id_cb82cdf163; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_on_usage_threshold_id_invoice_id_cb82cdf163 ON public.applied_usage_thresholds USING btree (usage_threshold_id, invoice_id);


--
-- Name: idx_on_wallet_transaction_id_ac2826109e; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_on_wallet_transaction_id_ac2826109e ON public.wallet_transactions_invoice_custom_sections USING btree (wallet_transaction_id);


--
-- Name: idx_pay_in_advance_duplication_guard_charge; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_pay_in_advance_duplication_guard_charge ON public.fees USING btree (pay_in_advance_event_transaction_id, charge_id) WHERE ((deleted_at IS NULL) AND (charge_filter_id IS NULL) AND (pay_in_advance_event_transaction_id IS NOT NULL) AND (pay_in_advance = true) AND (duplicated_in_advance = false) AND (original_fee_id IS NULL));


--
-- Name: idx_pay_in_advance_duplication_guard_charge_filter; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_pay_in_advance_duplication_guard_charge_filter ON public.fees USING btree (pay_in_advance_event_transaction_id, charge_id, charge_filter_id) WHERE ((deleted_at IS NULL) AND (charge_filter_id IS NOT NULL) AND (pay_in_advance_event_transaction_id IS NOT NULL) AND (pay_in_advance = true) AND (duplicated_in_advance = false) AND (original_fee_id IS NULL));


--
-- Name: idx_pif_values_on_filter_metric_filter_and_value; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_pif_values_on_filter_metric_filter_and_value ON public.product_filter_values USING btree (product_filter_id, billable_metric_filter_id, value) NULLS NOT DISTINCT WHERE (deleted_at IS NULL);


--
-- Name: idx_privileges_code_unique_per_feature; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_privileges_code_unique_per_feature ON public.entitlement_privileges USING btree (code, entitlement_feature_id) WHERE (deleted_at IS NULL);


--
-- Name: idx_subscription_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_subscription_unique ON public.usage_monitoring_subscription_activities USING btree (subscription_id);


--
-- Name: idx_unique_feature_per_catalog_plan; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_unique_feature_per_catalog_plan ON public.entitlement_entitlements USING btree (entitlement_feature_id, catalog_plan_id) WHERE (deleted_at IS NULL);


--
-- Name: idx_unique_feature_per_plan; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_unique_feature_per_plan ON public.entitlement_entitlements USING btree (entitlement_feature_id, plan_id) WHERE (deleted_at IS NULL);


--
-- Name: idx_unique_feature_per_subscription; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_unique_feature_per_subscription ON public.entitlement_entitlements USING btree (entitlement_feature_id, subscription_id) WHERE (deleted_at IS NULL);


--
-- Name: idx_unique_feature_removal_per_subscription; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_unique_feature_removal_per_subscription ON public.entitlement_subscription_feature_removals USING btree (subscription_id, entitlement_feature_id) WHERE (deleted_at IS NULL);


--
-- Name: idx_unique_privilege_removal_per_subscription; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_unique_privilege_removal_per_subscription ON public.entitlement_subscription_feature_removals USING btree (subscription_id, entitlement_privilege_id) WHERE (deleted_at IS NULL);


--
-- Name: idx_unique_tax_code_per_organization; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_unique_tax_code_per_organization ON public.taxes USING btree (code, organization_id) WHERE (deleted_at IS NULL);


--
-- Name: idx_usage_thresholds_on_amount_plan_recurring; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_usage_thresholds_on_amount_plan_recurring ON public.usage_thresholds USING btree (amount_cents, plan_id, recurring) WHERE ((deleted_at IS NULL) AND (plan_id IS NOT NULL));


--
-- Name: idx_usage_thresholds_on_amount_subscription_recurring; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_usage_thresholds_on_amount_subscription_recurring ON public.usage_thresholds USING btree (amount_cents, subscription_id, recurring) WHERE ((deleted_at IS NULL) AND (subscription_id IS NOT NULL));


--
-- Name: idx_usage_thresholds_plan_recurring; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_usage_thresholds_plan_recurring ON public.usage_thresholds USING btree (plan_id, recurring) WHERE ((recurring IS TRUE) AND (deleted_at IS NULL) AND (plan_id IS NOT NULL));


--
-- Name: idx_usage_thresholds_subscription_recurring; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_usage_thresholds_subscription_recurring ON public.usage_thresholds USING btree (subscription_id, recurring) WHERE ((recurring IS TRUE) AND (deleted_at IS NULL) AND (subscription_id IS NOT NULL));


--
-- Name: idx_wallet_transactions_available_inbound; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_wallet_transactions_available_inbound ON public.wallet_transactions USING btree (wallet_id, priority, (
CASE
    WHEN (transaction_status = 1) THEN 0
    ELSE 1
END), created_at) WHERE ((remaining_amount_cents > 0) AND (transaction_type = 0) AND (status = 1));


--
-- Name: idx_wallet_tx_consumptions_inbound_outbound; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_wallet_tx_consumptions_inbound_outbound ON public.wallet_transaction_consumptions USING btree (inbound_wallet_transaction_id, outbound_wallet_transaction_id);


--
-- Name: index_activation_rules_pending_with_expiry; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_activation_rules_pending_with_expiry ON public.subscription_activation_rules USING btree (status, expires_at) WHERE ((status = 'pending'::public.subscription_activation_rule_statuses) AND (expires_at IS NOT NULL));


--
-- Name: index_active_charge_filter_values; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_active_charge_filter_values ON public.charge_filter_values USING btree (charge_filter_id) WHERE (deleted_at IS NULL);


--
-- Name: index_active_charge_filters; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_active_charge_filters ON public.charge_filters USING btree (charge_id) WHERE (deleted_at IS NULL);


--
-- Name: index_active_metric_filters; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_active_metric_filters ON public.billable_metric_filters USING btree (billable_metric_id) WHERE (deleted_at IS NULL);


--
-- Name: index_active_storage_attachments_on_blob_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_active_storage_attachments_on_blob_id ON public.active_storage_attachments USING btree (blob_id);


--
-- Name: index_active_storage_attachments_uniqueness; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_active_storage_attachments_uniqueness ON public.active_storage_attachments USING btree (record_type, record_id, name, blob_id);


--
-- Name: index_active_storage_blobs_on_key; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_active_storage_blobs_on_key ON public.active_storage_blobs USING btree (key);


--
-- Name: index_active_storage_variant_records_uniqueness; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_active_storage_variant_records_uniqueness ON public.active_storage_variant_records USING btree (blob_id, variation_digest);


--
-- Name: index_add_ons_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_add_ons_on_deleted_at ON public.add_ons USING btree (deleted_at);


--
-- Name: index_add_ons_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_add_ons_on_organization_id ON public.add_ons USING btree (organization_id);


--
-- Name: index_add_ons_on_organization_id_and_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_add_ons_on_organization_id_and_code ON public.add_ons USING btree (organization_id, code) WHERE (deleted_at IS NULL);


--
-- Name: index_add_ons_taxes_on_add_on_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_add_ons_taxes_on_add_on_id ON public.add_ons_taxes USING btree (add_on_id);


--
-- Name: index_add_ons_taxes_on_add_on_id_and_tax_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_add_ons_taxes_on_add_on_id_and_tax_id ON public.add_ons_taxes USING btree (add_on_id, tax_id);


--
-- Name: index_add_ons_taxes_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_add_ons_taxes_on_organization_id ON public.add_ons_taxes USING btree (organization_id);


--
-- Name: index_add_ons_taxes_on_tax_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_add_ons_taxes_on_tax_id ON public.add_ons_taxes USING btree (tax_id);


--
-- Name: index_adjusted_fees_on_charge_filter_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_adjusted_fees_on_charge_filter_id ON public.adjusted_fees USING btree (charge_filter_id);


--
-- Name: index_adjusted_fees_on_charge_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_adjusted_fees_on_charge_id ON public.adjusted_fees USING btree (charge_id);


--
-- Name: index_adjusted_fees_on_fee_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_adjusted_fees_on_fee_id ON public.adjusted_fees USING btree (fee_id);


--
-- Name: index_adjusted_fees_on_group_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_adjusted_fees_on_group_id ON public.adjusted_fees USING btree (group_id);


--
-- Name: index_adjusted_fees_on_invoice_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_adjusted_fees_on_invoice_id ON public.adjusted_fees USING btree (invoice_id);


--
-- Name: index_adjusted_fees_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_adjusted_fees_on_organization_id ON public.adjusted_fees USING btree (organization_id);


--
-- Name: index_adjusted_fees_on_subscription_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_adjusted_fees_on_subscription_id ON public.adjusted_fees USING btree (subscription_id);


--
-- Name: index_ai_conversations_on_membership_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_ai_conversations_on_membership_id ON public.ai_conversations USING btree (membership_id);


--
-- Name: index_ai_conversations_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_ai_conversations_on_organization_id ON public.ai_conversations USING btree (organization_id);


--
-- Name: index_api_keys_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_api_keys_on_organization_id ON public.api_keys USING btree (organization_id);


--
-- Name: index_api_keys_on_value; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_api_keys_on_value ON public.api_keys USING btree (value);


--
-- Name: index_applied_add_ons_on_add_on_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_applied_add_ons_on_add_on_id ON public.applied_add_ons USING btree (add_on_id);


--
-- Name: index_applied_add_ons_on_add_on_id_and_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_applied_add_ons_on_add_on_id_and_customer_id ON public.applied_add_ons USING btree (add_on_id, customer_id);


--
-- Name: index_applied_add_ons_on_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_applied_add_ons_on_customer_id ON public.applied_add_ons USING btree (customer_id);


--
-- Name: index_applied_coupons_on_coupon_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_applied_coupons_on_coupon_id ON public.applied_coupons USING btree (coupon_id);


--
-- Name: index_applied_coupons_on_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_applied_coupons_on_customer_id ON public.applied_coupons USING btree (customer_id);


--
-- Name: index_applied_coupons_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_applied_coupons_on_organization_id ON public.applied_coupons USING btree (organization_id);


--
-- Name: index_applied_invoice_custom_sections_on_invoice_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_applied_invoice_custom_sections_on_invoice_id ON public.applied_invoice_custom_sections USING btree (invoice_id);


--
-- Name: index_applied_invoice_custom_sections_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_applied_invoice_custom_sections_on_organization_id ON public.applied_invoice_custom_sections USING btree (organization_id);


--
-- Name: index_applied_pricing_units_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_applied_pricing_units_on_organization_id ON public.applied_pricing_units USING btree (organization_id);


--
-- Name: index_applied_pricing_units_on_pricing_unit_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_applied_pricing_units_on_pricing_unit_id ON public.applied_pricing_units USING btree (pricing_unit_id);


--
-- Name: index_applied_pricing_units_on_pricing_unitable; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_applied_pricing_units_on_pricing_unitable ON public.applied_pricing_units USING btree (pricing_unitable_type, pricing_unitable_id);


--
-- Name: index_applied_usage_thresholds_on_invoice_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_applied_usage_thresholds_on_invoice_id ON public.applied_usage_thresholds USING btree (invoice_id);


--
-- Name: index_applied_usage_thresholds_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_applied_usage_thresholds_on_organization_id ON public.applied_usage_thresholds USING btree (organization_id);


--
-- Name: index_applied_usage_thresholds_on_usage_threshold_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_applied_usage_thresholds_on_usage_threshold_id ON public.applied_usage_thresholds USING btree (usage_threshold_id);


--
-- Name: index_billable_metric_filters_on_billable_metric_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_billable_metric_filters_on_billable_metric_id ON public.billable_metric_filters USING btree (billable_metric_id);


--
-- Name: index_billable_metric_filters_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_billable_metric_filters_on_deleted_at ON public.billable_metric_filters USING btree (deleted_at);


--
-- Name: index_billable_metric_filters_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_billable_metric_filters_on_organization_id ON public.billable_metric_filters USING btree (organization_id);


--
-- Name: index_billable_metrics_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_billable_metrics_on_deleted_at ON public.billable_metrics USING btree (deleted_at);


--
-- Name: index_billable_metrics_on_org_id_and_code_and_expr; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_billable_metrics_on_org_id_and_code_and_expr ON public.billable_metrics USING btree (organization_id, code, expression) WHERE ((expression IS NOT NULL) AND ((expression)::text <> ''::text));


--
-- Name: index_billable_metrics_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_billable_metrics_on_organization_id ON public.billable_metrics USING btree (organization_id);


--
-- Name: index_billable_metrics_on_organization_id_and_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_billable_metrics_on_organization_id_and_code ON public.billable_metrics USING btree (organization_id, code) WHERE (deleted_at IS NULL);


--
-- Name: index_billing_entities_on_applied_dunning_campaign_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_billing_entities_on_applied_dunning_campaign_id ON public.billing_entities USING btree (applied_dunning_campaign_id);


--
-- Name: index_billing_entities_on_code_and_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_billing_entities_on_code_and_organization_id ON public.billing_entities USING btree (code, organization_id) WHERE ((deleted_at IS NULL) AND (archived_at IS NULL));


--
-- Name: index_billing_entities_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_billing_entities_on_organization_id ON public.billing_entities USING btree (organization_id);


--
-- Name: index_billing_entities_taxes_on_billing_entity_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_billing_entities_taxes_on_billing_entity_id ON public.billing_entities_taxes USING btree (billing_entity_id);


--
-- Name: index_billing_entities_taxes_on_billing_entity_id_and_tax_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_billing_entities_taxes_on_billing_entity_id_and_tax_id ON public.billing_entities_taxes USING btree (billing_entity_id, tax_id);


--
-- Name: index_billing_entities_taxes_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_billing_entities_taxes_on_organization_id ON public.billing_entities_taxes USING btree (organization_id);


--
-- Name: index_billing_entities_taxes_on_tax_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_billing_entities_taxes_on_tax_id ON public.billing_entities_taxes USING btree (tax_id);


--
-- Name: index_billing_object_connections_on_integration_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_billing_object_connections_on_integration_customer_id ON public.billing_object_connections USING btree (integration_customer_id);


--
-- Name: index_billing_object_connections_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_billing_object_connections_on_organization_id ON public.billing_object_connections USING btree (organization_id);


--
-- Name: index_billing_segments_on_card_and_cycle; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_billing_segments_on_card_and_cycle ON public.billing_segments USING btree (contract_rate_card_id, cycle_started_at);


--
-- Name: index_billing_segments_on_card_and_period; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_billing_segments_on_card_and_period ON public.billing_segments USING btree (contract_rate_card_id, started_at);


--
-- Name: index_billing_segments_on_contract_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_billing_segments_on_contract_id ON public.billing_segments USING btree (contract_id);


--
-- Name: index_billing_segments_on_contract_rate_card_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_billing_segments_on_contract_rate_card_id ON public.billing_segments USING btree (contract_rate_card_id);


--
-- Name: index_billing_segments_on_customer_awaiting_invoicing; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_billing_segments_on_customer_awaiting_invoicing ON public.billing_segments USING btree (status, customer_id) WHERE (status = ANY (ARRAY['pending'::public.billing_segment_status, 'processing'::public.billing_segment_status]));


--
-- Name: index_billing_segments_on_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_billing_segments_on_customer_id ON public.billing_segments USING btree (customer_id);


--
-- Name: index_billing_segments_on_invoice_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_billing_segments_on_invoice_id ON public.billing_segments USING btree (invoice_id);


--
-- Name: index_billing_segments_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_billing_segments_on_organization_id ON public.billing_segments USING btree (organization_id);


--
-- Name: index_billing_segments_on_rate_override_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_billing_segments_on_rate_override_id ON public.billing_segments USING btree (rate_override_id);


--
-- Name: index_cached_aggregations_on_charge_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_cached_aggregations_on_charge_id ON public.cached_aggregations USING btree (charge_id);


--
-- Name: index_cached_aggregations_on_event_transaction_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_cached_aggregations_on_event_transaction_id ON public.cached_aggregations USING btree (organization_id, event_transaction_id);


--
-- Name: index_cached_aggregations_on_external_subscription_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_cached_aggregations_on_external_subscription_id ON public.cached_aggregations USING btree (external_subscription_id);


--
-- Name: index_catalog_plans_by_cursor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_catalog_plans_by_cursor ON public.catalog_plans USING btree (organization_id, created_at DESC, id DESC) WHERE (deleted_at IS NULL);


--
-- Name: index_catalog_plans_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_catalog_plans_on_deleted_at ON public.catalog_plans USING btree (deleted_at);


--
-- Name: index_catalog_plans_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_catalog_plans_on_organization_id ON public.catalog_plans USING btree (organization_id);


--
-- Name: index_catalog_plans_on_organization_id_and_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_catalog_plans_on_organization_id_and_code ON public.catalog_plans USING btree (organization_id, code) WHERE (deleted_at IS NULL);


--
-- Name: index_catalog_plans_on_organization_id_code_gin_trgm_ops; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_catalog_plans_on_organization_id_code_gin_trgm_ops ON public.catalog_plans USING gin (organization_id, code public.gin_trgm_ops) WHERE (deleted_at IS NULL);


--
-- Name: index_catalog_plans_on_organization_id_name_gin_trgm_ops; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_catalog_plans_on_organization_id_name_gin_trgm_ops ON public.catalog_plans USING gin (organization_id, name public.gin_trgm_ops) WHERE (deleted_at IS NULL);


--
-- Name: index_charge_filter_values_on_billable_metric_filter_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_charge_filter_values_on_billable_metric_filter_id ON public.charge_filter_values USING btree (billable_metric_filter_id);


--
-- Name: index_charge_filter_values_on_charge_filter_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_charge_filter_values_on_charge_filter_id ON public.charge_filter_values USING btree (charge_filter_id);


--
-- Name: index_charge_filter_values_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_charge_filter_values_on_deleted_at ON public.charge_filter_values USING btree (deleted_at);


--
-- Name: index_charge_filter_values_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_charge_filter_values_on_organization_id ON public.charge_filter_values USING btree (organization_id);


--
-- Name: index_charge_filters_on_charge_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_charge_filters_on_charge_id ON public.charge_filters USING btree (charge_id);


--
-- Name: index_charge_filters_on_charge_id_and_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_charge_filters_on_charge_id_and_code ON public.charge_filters USING btree (charge_id, code) WHERE (deleted_at IS NULL);


--
-- Name: index_charge_filters_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_charge_filters_on_deleted_at ON public.charge_filters USING btree (deleted_at);


--
-- Name: index_charge_filters_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_charge_filters_on_organization_id ON public.charge_filters USING btree (organization_id);


--
-- Name: index_charges_on_accepts_target_wallet; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_charges_on_accepts_target_wallet ON public.charges USING btree (accepts_target_wallet) WHERE (accepts_target_wallet = true);


--
-- Name: index_charges_on_billable_metric_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_charges_on_billable_metric_id ON public.charges USING btree (billable_metric_id) WHERE (deleted_at IS NULL);


--
-- Name: index_charges_on_billable_metric_id_all; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_charges_on_billable_metric_id_all ON public.charges USING btree (billable_metric_id);


--
-- Name: index_charges_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_charges_on_deleted_at ON public.charges USING btree (deleted_at);


--
-- Name: index_charges_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_charges_on_organization_id ON public.charges USING btree (organization_id);


--
-- Name: index_charges_on_parent_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_charges_on_parent_id ON public.charges USING btree (parent_id);


--
-- Name: index_charges_on_plan_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_charges_on_plan_id ON public.charges USING btree (plan_id);


--
-- Name: index_charges_on_plan_id_and_billable_metric_id_and_prorated; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_charges_on_plan_id_and_billable_metric_id_and_prorated ON public.charges USING btree (plan_id, billable_metric_id, prorated) WHERE (deleted_at IS NULL);


--
-- Name: index_charges_on_plan_id_and_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_charges_on_plan_id_and_code ON public.charges USING btree (plan_id, code) WHERE ((deleted_at IS NULL) AND (parent_id IS NULL));


--
-- Name: index_charges_pay_in_advance; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_charges_pay_in_advance ON public.charges USING btree (billable_metric_id) WHERE ((deleted_at IS NULL) AND (pay_in_advance = true));


--
-- Name: index_charges_taxes_on_charge_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_charges_taxes_on_charge_id ON public.charges_taxes USING btree (charge_id);


--
-- Name: index_charges_taxes_on_charge_id_and_tax_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_charges_taxes_on_charge_id_and_tax_id ON public.charges_taxes USING btree (charge_id, tax_id);


--
-- Name: index_charges_taxes_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_charges_taxes_on_organization_id ON public.charges_taxes USING btree (organization_id);


--
-- Name: index_charges_taxes_on_tax_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_charges_taxes_on_tax_id ON public.charges_taxes USING btree (tax_id);


--
-- Name: index_commitments_on_commitment_type_and_plan_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_commitments_on_commitment_type_and_plan_id ON public.commitments USING btree (commitment_type, plan_id);


--
-- Name: index_commitments_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_commitments_on_organization_id ON public.commitments USING btree (organization_id);


--
-- Name: index_commitments_on_plan_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_commitments_on_plan_id ON public.commitments USING btree (plan_id);


--
-- Name: index_commitments_taxes_on_commitment_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_commitments_taxes_on_commitment_id ON public.commitments_taxes USING btree (commitment_id);


--
-- Name: index_commitments_taxes_on_commitment_id_and_tax_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_commitments_taxes_on_commitment_id_and_tax_id ON public.commitments_taxes USING btree (commitment_id, tax_id);


--
-- Name: index_commitments_taxes_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_commitments_taxes_on_organization_id ON public.commitments_taxes USING btree (organization_id);


--
-- Name: index_commitments_taxes_on_tax_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_commitments_taxes_on_tax_id ON public.commitments_taxes USING btree (tax_id);


--
-- Name: index_contract_rate_cards_by_cursor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_contract_rate_cards_by_cursor ON public.contract_rate_cards USING btree (contract_id, created_at DESC, id DESC) WHERE (deleted_at IS NULL);


--
-- Name: index_contract_rate_cards_on_contract_and_rate_card; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_contract_rate_cards_on_contract_and_rate_card ON public.contract_rate_cards USING btree (contract_id, rate_card_id) WHERE (deleted_at IS NULL);


--
-- Name: index_contract_rate_cards_on_contract_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_contract_rate_cards_on_contract_id ON public.contract_rate_cards USING btree (contract_id);


--
-- Name: index_contract_rate_cards_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_contract_rate_cards_on_deleted_at ON public.contract_rate_cards USING btree (deleted_at);


--
-- Name: index_contract_rate_cards_on_id_and_contract_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_contract_rate_cards_on_id_and_contract_id ON public.contract_rate_cards USING btree (id, contract_id);


--
-- Name: index_contract_rate_cards_on_next_billing_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_contract_rate_cards_on_next_billing_at ON public.contract_rate_cards USING btree (next_billing_at) WHERE (deleted_at IS NULL);


--
-- Name: index_contract_rate_cards_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_contract_rate_cards_on_organization_id ON public.contract_rate_cards USING btree (organization_id);


--
-- Name: index_contract_rate_cards_on_rate_card_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_contract_rate_cards_on_rate_card_id ON public.contract_rate_cards USING btree (rate_card_id);


--
-- Name: index_contracts_by_cursor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_contracts_by_cursor ON public.contracts USING btree (organization_id, created_at DESC, id DESC);


--
-- Name: index_contracts_on_billing_entity_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_contracts_on_billing_entity_id ON public.contracts USING btree (billing_entity_id);


--
-- Name: index_contracts_on_catalog_plan_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_contracts_on_catalog_plan_id ON public.contracts USING btree (catalog_plan_id);


--
-- Name: index_contracts_on_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_contracts_on_customer_id ON public.contracts USING btree (customer_id);


--
-- Name: index_contracts_on_live_external_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_contracts_on_live_external_id ON public.contracts USING btree (organization_id, external_id, status) WHERE (status = ANY (ARRAY['pending'::public.contract_status, 'active'::public.contract_status]));


--
-- Name: index_contracts_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_contracts_on_organization_id ON public.contracts USING btree (organization_id);


--
-- Name: index_contracts_on_organization_id_and_external_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_contracts_on_organization_id_and_external_id ON public.contracts USING btree (organization_id, external_id);


--
-- Name: index_contracts_on_organization_id_external_id_gin_trgm_ops; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_contracts_on_organization_id_external_id_gin_trgm_ops ON public.contracts USING gin (organization_id, external_id public.gin_trgm_ops);


--
-- Name: index_contracts_on_organization_id_name_gin_trgm_ops; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_contracts_on_organization_id_name_gin_trgm_ops ON public.contracts USING gin (organization_id, name public.gin_trgm_ops);


--
-- Name: index_contracts_on_payment_method_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_contracts_on_payment_method_id ON public.contracts USING btree (payment_method_id);


--
-- Name: index_coupon_targets_on_billable_metric_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_coupon_targets_on_billable_metric_id ON public.coupon_targets USING btree (billable_metric_id);


--
-- Name: index_coupon_targets_on_catalog_plan_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_coupon_targets_on_catalog_plan_id ON public.coupon_targets USING btree (catalog_plan_id);


--
-- Name: index_coupon_targets_on_coupon_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_coupon_targets_on_coupon_id ON public.coupon_targets USING btree (coupon_id);


--
-- Name: index_coupon_targets_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_coupon_targets_on_deleted_at ON public.coupon_targets USING btree (deleted_at);


--
-- Name: index_coupon_targets_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_coupon_targets_on_organization_id ON public.coupon_targets USING btree (organization_id);


--
-- Name: index_coupon_targets_on_plan_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_coupon_targets_on_plan_id ON public.coupon_targets USING btree (plan_id);


--
-- Name: index_coupons_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_coupons_on_deleted_at ON public.coupons USING btree (deleted_at);


--
-- Name: index_coupons_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_coupons_on_organization_id ON public.coupons USING btree (organization_id);


--
-- Name: index_coupons_on_organization_id_and_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_coupons_on_organization_id_and_code ON public.coupons USING btree (organization_id, code) WHERE (deleted_at IS NULL);


--
-- Name: index_credit_note_items_on_credit_note_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_credit_note_items_on_credit_note_id ON public.credit_note_items USING btree (credit_note_id);


--
-- Name: index_credit_note_items_on_fee_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_credit_note_items_on_fee_id ON public.credit_note_items USING btree (fee_id);


--
-- Name: index_credit_note_items_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_credit_note_items_on_organization_id ON public.credit_note_items USING btree (organization_id);


--
-- Name: index_credit_notes_on_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_credit_notes_on_customer_id ON public.credit_notes USING btree (customer_id);


--
-- Name: index_credit_notes_on_invoice_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_credit_notes_on_invoice_id ON public.credit_notes USING btree (invoice_id);


--
-- Name: index_credit_notes_on_invoice_id_and_sequential_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_credit_notes_on_invoice_id_and_sequential_id ON public.credit_notes USING btree (invoice_id, sequential_id);


--
-- Name: index_credit_notes_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_credit_notes_on_organization_id ON public.credit_notes USING btree (organization_id);


--
-- Name: index_credit_notes_taxes_on_credit_note_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_credit_notes_taxes_on_credit_note_id ON public.credit_notes_taxes USING btree (credit_note_id);


--
-- Name: index_credit_notes_taxes_on_note_id_code_rate; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_credit_notes_taxes_on_note_id_code_rate ON public.credit_notes_taxes USING btree (credit_note_id, tax_code, tax_rate);


--
-- Name: index_credit_notes_taxes_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_credit_notes_taxes_on_organization_id ON public.credit_notes_taxes USING btree (organization_id);


--
-- Name: index_credit_notes_taxes_on_tax_code; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_credit_notes_taxes_on_tax_code ON public.credit_notes_taxes USING btree (tax_code);


--
-- Name: index_credit_notes_taxes_on_tax_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_credit_notes_taxes_on_tax_id ON public.credit_notes_taxes USING btree (tax_id);


--
-- Name: index_credits_on_applied_coupon_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_credits_on_applied_coupon_id ON public.credits USING btree (applied_coupon_id);


--
-- Name: index_credits_on_credit_note_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_credits_on_credit_note_id ON public.credits USING btree (credit_note_id);


--
-- Name: index_credits_on_invoice_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_credits_on_invoice_id ON public.credits USING btree (invoice_id);


--
-- Name: index_credits_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_credits_on_organization_id ON public.credits USING btree (organization_id);


--
-- Name: index_credits_on_progressive_billing_invoice_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_credits_on_progressive_billing_invoice_id ON public.credits USING btree (progressive_billing_invoice_id);


--
-- Name: index_cs_admin_audit_logs_on_actor_user_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_cs_admin_audit_logs_on_actor_user_id ON public.cs_admin_audit_logs USING btree (actor_user_id);


--
-- Name: index_cs_admin_audit_logs_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_cs_admin_audit_logs_on_organization_id ON public.cs_admin_audit_logs USING btree (organization_id);


--
-- Name: index_customer_metadata_on_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customer_metadata_on_customer_id ON public.customer_metadata USING btree (customer_id);


--
-- Name: index_customer_metadata_on_customer_id_and_key; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_customer_metadata_on_customer_id_and_key ON public.customer_metadata USING btree (customer_id, key);


--
-- Name: index_customer_metadata_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customer_metadata_on_organization_id ON public.customer_metadata USING btree (organization_id);


--
-- Name: index_customers_by_cursor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customers_by_cursor ON public.customers USING btree (organization_id, created_at DESC, id);


--
-- Name: index_customers_invoice_custom_sections_on_billing_entity_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customers_invoice_custom_sections_on_billing_entity_id ON public.customers_invoice_custom_sections USING btree (billing_entity_id);


--
-- Name: index_customers_invoice_custom_sections_on_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customers_invoice_custom_sections_on_customer_id ON public.customers_invoice_custom_sections USING btree (customer_id);


--
-- Name: index_customers_invoice_custom_sections_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customers_invoice_custom_sections_on_organization_id ON public.customers_invoice_custom_sections USING btree (organization_id);


--
-- Name: index_customers_on_account_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customers_on_account_type ON public.customers USING btree (account_type);


--
-- Name: index_customers_on_applied_dunning_campaign_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customers_on_applied_dunning_campaign_id ON public.customers USING btree (applied_dunning_campaign_id);


--
-- Name: index_customers_on_awaiting_wallet_refresh; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customers_on_awaiting_wallet_refresh ON public.customers USING btree (awaiting_wallet_refresh);


--
-- Name: index_customers_on_billing_entity_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customers_on_billing_entity_id ON public.customers USING btree (billing_entity_id);


--
-- Name: index_customers_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customers_on_deleted_at ON public.customers USING btree (deleted_at);


--
-- Name: index_customers_on_external_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customers_on_external_id ON public.customers USING btree (organization_id, external_id);


--
-- Name: index_customers_on_external_id_and_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_customers_on_external_id_and_organization_id ON public.customers USING btree (external_id, organization_id) WHERE (deleted_at IS NULL);


--
-- Name: index_customers_on_org_id_and_sequential_id_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_customers_on_org_id_and_sequential_id_unique ON public.customers USING btree (organization_id, sequential_id) WHERE (sequential_id IS NOT NULL);


--
-- Name: index_customers_on_organization_id_email_gin_trgm_ops; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customers_on_organization_id_email_gin_trgm_ops ON public.customers USING gin (organization_id, email public.gin_trgm_ops) WHERE (deleted_at IS NULL);


--
-- Name: index_customers_on_organization_id_external_id_gin_trgm_ops; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customers_on_organization_id_external_id_gin_trgm_ops ON public.customers USING gin (organization_id, external_id public.gin_trgm_ops) WHERE (deleted_at IS NULL);


--
-- Name: index_customers_on_organization_id_firstname_gin_trgm_ops; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customers_on_organization_id_firstname_gin_trgm_ops ON public.customers USING gin (organization_id, firstname public.gin_trgm_ops) WHERE (deleted_at IS NULL);


--
-- Name: index_customers_on_organization_id_kept; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customers_on_organization_id_kept ON public.customers USING btree (organization_id) WHERE (deleted_at IS NULL);


--
-- Name: index_customers_on_organization_id_lastname_gin_trgm_ops; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customers_on_organization_id_lastname_gin_trgm_ops ON public.customers USING gin (organization_id, lastname public.gin_trgm_ops) WHERE (deleted_at IS NULL);


--
-- Name: index_customers_on_organization_id_legal_name_gin_trgm_ops; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customers_on_organization_id_legal_name_gin_trgm_ops ON public.customers USING gin (organization_id, legal_name public.gin_trgm_ops) WHERE (deleted_at IS NULL);


--
-- Name: index_customers_on_organization_id_name_gin_trgm_ops; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customers_on_organization_id_name_gin_trgm_ops ON public.customers USING gin (organization_id, name public.gin_trgm_ops) WHERE (deleted_at IS NULL);


--
-- Name: index_customers_on_sequential_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customers_on_sequential_id ON public.customers USING btree (sequential_id);


--
-- Name: index_customers_taxes_on_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customers_taxes_on_customer_id ON public.customers_taxes USING btree (customer_id);


--
-- Name: index_customers_taxes_on_customer_id_and_tax_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_customers_taxes_on_customer_id_and_tax_id ON public.customers_taxes USING btree (customer_id, tax_id);


--
-- Name: index_customers_taxes_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customers_taxes_on_organization_id ON public.customers_taxes USING btree (organization_id);


--
-- Name: index_customers_taxes_on_tax_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_customers_taxes_on_tax_id ON public.customers_taxes USING btree (tax_id);


--
-- Name: index_daily_usages_on_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_daily_usages_on_customer_id ON public.daily_usages USING btree (customer_id);


--
-- Name: index_daily_usages_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_daily_usages_on_organization_id ON public.daily_usages USING btree (organization_id);


--
-- Name: index_daily_usages_on_subscription_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_daily_usages_on_subscription_id ON public.daily_usages USING btree (subscription_id);


--
-- Name: index_daily_usages_on_subscription_id_and_usage_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_daily_usages_on_subscription_id_and_usage_date ON public.daily_usages USING btree (subscription_id, usage_date);


--
-- Name: index_daily_usages_on_usage_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_daily_usages_on_usage_date ON public.daily_usages USING btree (usage_date);


--
-- Name: index_data_export_parts_on_data_export_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_data_export_parts_on_data_export_id ON public.data_export_parts USING btree (data_export_id);


--
-- Name: index_data_export_parts_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_data_export_parts_on_organization_id ON public.data_export_parts USING btree (organization_id);


--
-- Name: index_data_exports_on_membership_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_data_exports_on_membership_id ON public.data_exports USING btree (membership_id);


--
-- Name: index_data_exports_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_data_exports_on_organization_id ON public.data_exports USING btree (organization_id);


--
-- Name: index_dunning_campaign_thresholds_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_dunning_campaign_thresholds_on_deleted_at ON public.dunning_campaign_thresholds USING btree (deleted_at);


--
-- Name: index_dunning_campaign_thresholds_on_dunning_campaign_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_dunning_campaign_thresholds_on_dunning_campaign_id ON public.dunning_campaign_thresholds USING btree (dunning_campaign_id);


--
-- Name: index_dunning_campaign_thresholds_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_dunning_campaign_thresholds_on_organization_id ON public.dunning_campaign_thresholds USING btree (organization_id);


--
-- Name: index_dunning_campaigns_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_dunning_campaigns_on_deleted_at ON public.dunning_campaigns USING btree (deleted_at);


--
-- Name: index_dunning_campaigns_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_dunning_campaigns_on_organization_id ON public.dunning_campaigns USING btree (organization_id);


--
-- Name: index_dunning_campaigns_on_organization_id_and_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_dunning_campaigns_on_organization_id_and_code ON public.dunning_campaigns USING btree (organization_id, code) WHERE (deleted_at IS NULL);


--
-- Name: index_entitlement_entitlement_values_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_entitlement_entitlement_values_on_organization_id ON public.entitlement_entitlement_values USING btree (organization_id);


--
-- Name: index_entitlement_entitlements_on_catalog_plan_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_entitlement_entitlements_on_catalog_plan_id ON public.entitlement_entitlements USING btree (catalog_plan_id);


--
-- Name: index_entitlement_entitlements_on_entitlement_feature_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_entitlement_entitlements_on_entitlement_feature_id ON public.entitlement_entitlements USING btree (entitlement_feature_id);


--
-- Name: index_entitlement_entitlements_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_entitlement_entitlements_on_organization_id ON public.entitlement_entitlements USING btree (organization_id);


--
-- Name: index_entitlement_entitlements_on_plan_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_entitlement_entitlements_on_plan_id ON public.entitlement_entitlements USING btree (plan_id);


--
-- Name: index_entitlement_entitlements_on_subscription_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_entitlement_entitlements_on_subscription_id ON public.entitlement_entitlements USING btree (subscription_id);


--
-- Name: index_entitlement_features_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_entitlement_features_on_organization_id ON public.entitlement_features USING btree (organization_id);


--
-- Name: index_entitlement_privileges_on_entitlement_feature_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_entitlement_privileges_on_entitlement_feature_id ON public.entitlement_privileges USING btree (entitlement_feature_id);


--
-- Name: index_entitlement_privileges_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_entitlement_privileges_on_organization_id ON public.entitlement_privileges USING btree (organization_id);


--
-- Name: index_entitlement_subscription_feature_removals_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_entitlement_subscription_feature_removals_on_deleted_at ON public.entitlement_subscription_feature_removals USING btree (deleted_at);


--
-- Name: index_error_details_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_error_details_on_deleted_at ON public.error_details USING btree (deleted_at);


--
-- Name: index_error_details_on_error_code; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_error_details_on_error_code ON public.error_details USING btree (error_code);


--
-- Name: index_error_details_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_error_details_on_organization_id ON public.error_details USING btree (organization_id);


--
-- Name: index_error_details_on_owner; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_error_details_on_owner ON public.error_details USING btree (owner_type, owner_id);


--
-- Name: index_events_on_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_events_on_created_at ON public.events USING btree (created_at) WHERE (deleted_at IS NULL);


--
-- Name: index_events_on_organization_id_and_code; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_events_on_organization_id_and_code ON public.events USING btree (organization_id, code);


--
-- Name: index_events_on_organization_id_and_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_events_on_organization_id_and_created_at ON public.events USING btree (organization_id, created_at DESC) WHERE (deleted_at IS NULL);


--
-- Name: index_events_on_organization_id_and_timestamp; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_events_on_organization_id_and_timestamp ON public.events USING btree (organization_id, "timestamp" DESC) WHERE (deleted_at IS NULL);


--
-- Name: index_events_on_organization_id_and_transaction_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_events_on_organization_id_and_transaction_id ON public.events USING btree (organization_id, transaction_id) WHERE (deleted_at IS NULL);


--
-- Name: index_fees_on_add_on_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_on_add_on_id ON public.fees USING btree (add_on_id);


--
-- Name: index_fees_on_applied_add_on_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_on_applied_add_on_id ON public.fees USING btree (applied_add_on_id);


--
-- Name: index_fees_on_billing_entity_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_on_billing_entity_id ON public.fees USING btree (billing_entity_id);


--
-- Name: index_fees_on_charge_filter_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_on_charge_filter_id ON public.fees USING btree (charge_filter_id);


--
-- Name: index_fees_on_charge_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_on_charge_id ON public.fees USING btree (charge_id);


--
-- Name: index_fees_on_charge_id_and_invoice_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_on_charge_id_and_invoice_id ON public.fees USING btree (charge_id, invoice_id) WHERE (deleted_at IS NULL);


--
-- Name: index_fees_on_contract_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_on_contract_id ON public.fees USING btree (contract_id);


--
-- Name: index_fees_on_contract_rate_card_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_on_contract_rate_card_id ON public.fees USING btree (contract_rate_card_id);


--
-- Name: index_fees_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_on_deleted_at ON public.fees USING btree (deleted_at);


--
-- Name: index_fees_on_fixed_charge_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_on_fixed_charge_id ON public.fees USING btree (fixed_charge_id);


--
-- Name: index_fees_on_group_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_on_group_id ON public.fees USING btree (group_id);


--
-- Name: index_fees_on_invoice_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_on_invoice_id ON public.fees USING btree (invoice_id);


--
-- Name: index_fees_on_invoiceable; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_on_invoiceable ON public.fees USING btree (invoiceable_type, invoiceable_id);


--
-- Name: index_fees_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_on_organization_id ON public.fees USING btree (organization_id);


--
-- Name: index_fees_on_organization_id_and_created_at_and_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_on_organization_id_and_created_at_and_id ON public.fees USING btree (organization_id, created_at, id) WHERE (deleted_at IS NULL);


--
-- Name: index_fees_on_original_fee_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_on_original_fee_id ON public.fees USING btree (original_fee_id);


--
-- Name: index_fees_on_pay_in_advance_event_transaction_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_on_pay_in_advance_event_transaction_id ON public.fees USING btree (pay_in_advance_event_transaction_id) WHERE (deleted_at IS NULL);


--
-- Name: index_fees_on_product_filter_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_on_product_filter_id ON public.fees USING btree (product_filter_id);


--
-- Name: index_fees_on_rate_card_rate_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_on_rate_card_rate_id ON public.fees USING btree (rate_card_rate_id);


--
-- Name: index_fees_on_rate_override_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_on_rate_override_id ON public.fees USING btree (rate_override_id);


--
-- Name: index_fees_on_subscription_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_on_subscription_id ON public.fees USING btree (subscription_id);


--
-- Name: index_fees_on_true_up_parent_fee_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_on_true_up_parent_fee_id ON public.fees USING btree (true_up_parent_fee_id);


--
-- Name: index_fees_taxes_on_fee_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_taxes_on_fee_id ON public.fees_taxes USING btree (fee_id);


--
-- Name: index_fees_taxes_on_fee_id_and_tax_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_fees_taxes_on_fee_id_and_tax_id ON public.fees_taxes USING btree (fee_id, tax_id) WHERE ((tax_id IS NOT NULL) AND (created_at >= '2023-09-12 00:00:00'::timestamp without time zone));


--
-- Name: index_fees_taxes_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_taxes_on_organization_id ON public.fees_taxes USING btree (organization_id);


--
-- Name: index_fees_taxes_on_tax_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fees_taxes_on_tax_id ON public.fees_taxes USING btree (tax_id);


--
-- Name: index_fixed_charge_events_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fixed_charge_events_on_deleted_at ON public.fixed_charge_events USING btree (deleted_at);


--
-- Name: index_fixed_charge_events_on_fixed_charge_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fixed_charge_events_on_fixed_charge_id ON public.fixed_charge_events USING btree (fixed_charge_id);


--
-- Name: index_fixed_charge_events_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fixed_charge_events_on_organization_id ON public.fixed_charge_events USING btree (organization_id);


--
-- Name: index_fixed_charge_events_on_subscription_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fixed_charge_events_on_subscription_id ON public.fixed_charge_events USING btree (subscription_id);


--
-- Name: index_fixed_charges_on_add_on_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fixed_charges_on_add_on_id ON public.fixed_charges USING btree (add_on_id);


--
-- Name: index_fixed_charges_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fixed_charges_on_deleted_at ON public.fixed_charges USING btree (deleted_at);


--
-- Name: index_fixed_charges_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fixed_charges_on_organization_id ON public.fixed_charges USING btree (organization_id);


--
-- Name: index_fixed_charges_on_parent_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fixed_charges_on_parent_id ON public.fixed_charges USING btree (parent_id);


--
-- Name: index_fixed_charges_on_plan_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fixed_charges_on_plan_id ON public.fixed_charges USING btree (plan_id);


--
-- Name: index_fixed_charges_on_plan_id_and_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_fixed_charges_on_plan_id_and_code ON public.fixed_charges USING btree (plan_id, code) WHERE ((deleted_at IS NULL) AND (parent_id IS NULL));


--
-- Name: index_fixed_charges_taxes_on_fixed_charge_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fixed_charges_taxes_on_fixed_charge_id ON public.fixed_charges_taxes USING btree (fixed_charge_id);


--
-- Name: index_fixed_charges_taxes_on_fixed_charge_id_and_tax_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_fixed_charges_taxes_on_fixed_charge_id_and_tax_id ON public.fixed_charges_taxes USING btree (fixed_charge_id, tax_id);


--
-- Name: index_fixed_charges_taxes_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fixed_charges_taxes_on_organization_id ON public.fixed_charges_taxes USING btree (organization_id);


--
-- Name: index_fixed_charges_taxes_on_tax_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_fixed_charges_taxes_on_tax_id ON public.fixed_charges_taxes USING btree (tax_id);


--
-- Name: index_group_properties_on_charge_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_group_properties_on_charge_id ON public.group_properties USING btree (charge_id);


--
-- Name: index_group_properties_on_charge_id_and_group_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_group_properties_on_charge_id_and_group_id ON public.group_properties USING btree (charge_id, group_id);


--
-- Name: index_group_properties_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_group_properties_on_deleted_at ON public.group_properties USING btree (deleted_at);


--
-- Name: index_group_properties_on_group_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_group_properties_on_group_id ON public.group_properties USING btree (group_id);


--
-- Name: index_groups_on_billable_metric_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_groups_on_billable_metric_id ON public.groups USING btree (billable_metric_id);


--
-- Name: index_groups_on_billable_metric_id_and_parent_group_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_groups_on_billable_metric_id_and_parent_group_id ON public.groups USING btree (billable_metric_id, parent_group_id);


--
-- Name: index_groups_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_groups_on_deleted_at ON public.groups USING btree (deleted_at);


--
-- Name: index_groups_on_parent_group_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_groups_on_parent_group_id ON public.groups USING btree (parent_group_id);


--
-- Name: index_idempotency_records_on_idempotency_key; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_idempotency_records_on_idempotency_key ON public.idempotency_records USING btree (idempotency_key);


--
-- Name: index_idempotency_records_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_idempotency_records_on_organization_id ON public.idempotency_records USING btree (organization_id);


--
-- Name: index_idempotency_records_on_resource_type_and_resource_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_idempotency_records_on_resource_type_and_resource_id ON public.idempotency_records USING btree (resource_type, resource_id);


--
-- Name: index_inbound_webhooks_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_inbound_webhooks_on_organization_id ON public.inbound_webhooks USING btree (organization_id);


--
-- Name: index_inbound_webhooks_on_status_and_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_inbound_webhooks_on_status_and_created_at ON public.inbound_webhooks USING btree (status, created_at) WHERE (status = 'pending'::public.inbound_webhook_status);


--
-- Name: index_inbound_webhooks_on_status_and_processing_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_inbound_webhooks_on_status_and_processing_at ON public.inbound_webhooks USING btree (status, processing_at) WHERE (status = 'processing'::public.inbound_webhook_status);


--
-- Name: index_int_collection_mappings_unique_billing_entity_is_not_null; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_int_collection_mappings_unique_billing_entity_is_not_null ON public.integration_collection_mappings USING btree (mapping_type, integration_id, billing_entity_id) WHERE (billing_entity_id IS NOT NULL);


--
-- Name: index_int_collection_mappings_unique_billing_entity_is_null; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_int_collection_mappings_unique_billing_entity_is_null ON public.integration_collection_mappings USING btree (mapping_type, integration_id, organization_id) WHERE (billing_entity_id IS NULL);


--
-- Name: index_int_items_on_external_id_and_int_id_and_type; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_int_items_on_external_id_and_int_id_and_type ON public.integration_items USING btree (external_id, integration_id, item_type);


--
-- Name: index_integration_collection_mappings_on_billing_entity_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_integration_collection_mappings_on_billing_entity_id ON public.integration_collection_mappings USING btree (billing_entity_id);


--
-- Name: index_integration_collection_mappings_on_integration_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_integration_collection_mappings_on_integration_id ON public.integration_collection_mappings USING btree (integration_id);


--
-- Name: index_integration_collection_mappings_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_integration_collection_mappings_on_organization_id ON public.integration_collection_mappings USING btree (organization_id);


--
-- Name: index_integration_customers_on_customer_category_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_integration_customers_on_customer_category_code ON public.integration_customers USING btree (customer_id, category, code);


--
-- Name: index_integration_customers_on_customer_category_default; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_integration_customers_on_customer_category_default ON public.integration_customers USING btree (customer_id, category) WHERE is_default;


--
-- Name: index_integration_customers_on_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_integration_customers_on_customer_id ON public.integration_customers USING btree (customer_id);


--
-- Name: index_integration_customers_on_customer_id_and_type; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_integration_customers_on_customer_id_and_type ON public.integration_customers USING btree (customer_id, type);


--
-- Name: index_integration_customers_on_external_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_integration_customers_on_external_customer_id ON public.integration_customers USING btree (external_customer_id);


--
-- Name: index_integration_customers_on_integration_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_integration_customers_on_integration_id ON public.integration_customers USING btree (integration_id);


--
-- Name: index_integration_customers_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_integration_customers_on_organization_id ON public.integration_customers USING btree (organization_id);


--
-- Name: index_integration_items_on_integration_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_integration_items_on_integration_id ON public.integration_items USING btree (integration_id);


--
-- Name: index_integration_items_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_integration_items_on_organization_id ON public.integration_items USING btree (organization_id);


--
-- Name: index_integration_mappings_on_integration_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_integration_mappings_on_integration_id ON public.integration_mappings USING btree (integration_id);


--
-- Name: index_integration_mappings_on_mappable; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_integration_mappings_on_mappable ON public.integration_mappings USING btree (mappable_type, mappable_id);


--
-- Name: index_integration_mappings_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_integration_mappings_on_organization_id ON public.integration_mappings USING btree (organization_id);


--
-- Name: index_integration_mappings_unique_billing_entity_id_is_not_null; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_integration_mappings_unique_billing_entity_id_is_not_null ON public.integration_mappings USING btree (mappable_type, mappable_id, integration_id, billing_entity_id) WHERE (billing_entity_id IS NOT NULL);


--
-- Name: index_integration_mappings_unique_billing_entity_id_is_null; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_integration_mappings_unique_billing_entity_id_is_null ON public.integration_mappings USING btree (mappable_type, mappable_id, integration_id, organization_id) WHERE (billing_entity_id IS NULL);


--
-- Name: index_integration_resources_on_integration_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_integration_resources_on_integration_id ON public.integration_resources USING btree (integration_id);


--
-- Name: index_integration_resources_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_integration_resources_on_organization_id ON public.integration_resources USING btree (organization_id);


--
-- Name: index_integration_resources_on_syncable; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_integration_resources_on_syncable ON public.integration_resources USING btree (syncable_type, syncable_id);


--
-- Name: index_integrations_on_code_and_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_integrations_on_code_and_organization_id ON public.integrations USING btree (code, organization_id);


--
-- Name: index_integrations_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_integrations_on_organization_id ON public.integrations USING btree (organization_id);


--
-- Name: index_invites_on_membership_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invites_on_membership_id ON public.invites USING btree (membership_id);


--
-- Name: index_invites_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invites_on_organization_id ON public.invites USING btree (organization_id);


--
-- Name: index_invites_on_token; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_invites_on_token ON public.invites USING btree (token);


--
-- Name: index_invoice_connections_on_integration_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoice_connections_on_integration_customer_id ON public.invoice_connections USING btree (integration_customer_id);


--
-- Name: index_invoice_connections_on_invoice_id_and_category; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_invoice_connections_on_invoice_id_and_category ON public.invoice_connections USING btree (invoice_id, category);


--
-- Name: index_invoice_connections_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoice_connections_on_organization_id ON public.invoice_connections USING btree (organization_id);


--
-- Name: index_invoice_connections_on_payment_provider_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoice_connections_on_payment_provider_customer_id ON public.invoice_connections USING btree (payment_provider_customer_id);


--
-- Name: index_invoice_custom_sections_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoice_custom_sections_on_organization_id ON public.invoice_custom_sections USING btree (organization_id);


--
-- Name: index_invoice_custom_sections_on_organization_id_and_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_invoice_custom_sections_on_organization_id_and_code ON public.invoice_custom_sections USING btree (organization_id, code) WHERE (deleted_at IS NULL);


--
-- Name: index_invoice_custom_sections_on_section_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoice_custom_sections_on_section_type ON public.invoice_custom_sections USING btree (section_type);


--
-- Name: index_invoice_metadata_on_invoice_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoice_metadata_on_invoice_id ON public.invoice_metadata USING btree (invoice_id);


--
-- Name: index_invoice_metadata_on_invoice_id_and_key; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_invoice_metadata_on_invoice_id_and_key ON public.invoice_metadata USING btree (invoice_id, key);


--
-- Name: index_invoice_metadata_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoice_metadata_on_organization_id ON public.invoice_metadata USING btree (organization_id);


--
-- Name: index_invoice_settlements_on_billing_entity_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoice_settlements_on_billing_entity_id ON public.invoice_settlements USING btree (billing_entity_id);


--
-- Name: index_invoice_settlements_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoice_settlements_on_organization_id ON public.invoice_settlements USING btree (organization_id);


--
-- Name: index_invoice_settlements_on_source_credit_note_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoice_settlements_on_source_credit_note_id ON public.invoice_settlements USING btree (source_credit_note_id);


--
-- Name: index_invoice_settlements_on_source_payment_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoice_settlements_on_source_payment_id ON public.invoice_settlements USING btree (source_payment_id);


--
-- Name: index_invoice_settlements_on_target_invoice_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoice_settlements_on_target_invoice_id ON public.invoice_settlements USING btree (target_invoice_id);


--
-- Name: index_invoice_subscriptions_boundaries; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoice_subscriptions_boundaries ON public.invoice_subscriptions USING btree (subscription_id, from_datetime, to_datetime);


--
-- Name: index_invoice_subscriptions_on_invoice_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoice_subscriptions_on_invoice_id ON public.invoice_subscriptions USING btree (invoice_id);


--
-- Name: index_invoice_subscriptions_on_invoice_id_and_subscription_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_invoice_subscriptions_on_invoice_id_and_subscription_id ON public.invoice_subscriptions USING btree (invoice_id, subscription_id) WHERE (created_at >= '2023-11-23 00:00:00'::timestamp without time zone);


--
-- Name: index_invoice_subscriptions_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoice_subscriptions_on_organization_id ON public.invoice_subscriptions USING btree (organization_id);


--
-- Name: index_invoice_subscriptions_on_regenerated_invoice_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoice_subscriptions_on_regenerated_invoice_id ON public.invoice_subscriptions USING btree (regenerated_invoice_id);


--
-- Name: index_invoice_subscriptions_on_subscription_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoice_subscriptions_on_subscription_id ON public.invoice_subscriptions USING btree (subscription_id);


--
-- Name: index_invoices_by_cursor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoices_by_cursor ON public.invoices USING btree (organization_id, issuing_date DESC, created_at DESC, id);


--
-- Name: index_invoices_on_customer_billing_entity_sequential; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_invoices_on_customer_billing_entity_sequential ON public.invoices USING btree (customer_id, billing_entity_id, sequential_id);


--
-- Name: index_invoices_on_number; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoices_on_number ON public.invoices USING btree (number);


--
-- Name: index_invoices_on_organization_id_and_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoices_on_organization_id_and_customer_id ON public.invoices USING btree (customer_id, organization_id);


--
-- Name: index_invoices_on_organization_id_lower_purchase_order_number; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoices_on_organization_id_lower_purchase_order_number ON public.invoices USING btree (organization_id, lower((purchase_order_number)::text));


--
-- Name: index_invoices_on_organization_id_number_gin_trgm_ops; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoices_on_organization_id_number_gin_trgm_ops ON public.invoices USING gin (organization_id, number public.gin_trgm_ops);


--
-- Name: index_invoices_on_organization_id_search_terms_gin_trgm_ops; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoices_on_organization_id_search_terms_gin_trgm_ops ON public.invoices USING gin (organization_id, search_terms public.gin_trgm_ops);


--
-- Name: index_invoices_on_payment_due_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoices_on_payment_due_date ON public.invoices USING btree (payment_due_date) WHERE ((status = 1) AND (payment_status <> 1) AND (payment_overdue = false) AND (payment_dispute_lost_at IS NULL));


--
-- Name: index_invoices_on_payment_method_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoices_on_payment_method_id ON public.invoices USING btree (payment_method_id);


--
-- Name: index_invoices_on_ready_to_be_refreshed; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoices_on_ready_to_be_refreshed ON public.invoices USING btree (ready_to_be_refreshed) WHERE (ready_to_be_refreshed = true);


--
-- Name: index_invoices_on_voided_invoice_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoices_on_voided_invoice_id ON public.invoices USING btree (voided_invoice_id);


--
-- Name: index_invoices_payment_requests_on_invoice_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoices_payment_requests_on_invoice_id ON public.invoices_payment_requests USING btree (invoice_id);


--
-- Name: index_invoices_payment_requests_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoices_payment_requests_on_organization_id ON public.invoices_payment_requests USING btree (organization_id);


--
-- Name: index_invoices_payment_requests_on_payment_request_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoices_payment_requests_on_payment_request_id ON public.invoices_payment_requests USING btree (payment_request_id);


--
-- Name: index_invoices_taxes_on_invoice_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoices_taxes_on_invoice_id ON public.invoices_taxes USING btree (invoice_id);


--
-- Name: index_invoices_taxes_on_invoice_id_and_tax_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_invoices_taxes_on_invoice_id_and_tax_id ON public.invoices_taxes USING btree (invoice_id, tax_id) WHERE ((tax_id IS NOT NULL) AND (created_at >= '2023-09-12 00:00:00'::timestamp without time zone));


--
-- Name: index_invoices_taxes_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoices_taxes_on_organization_id ON public.invoices_taxes USING btree (organization_id);


--
-- Name: index_invoices_taxes_on_tax_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_invoices_taxes_on_tax_id ON public.invoices_taxes USING btree (tax_id);


--
-- Name: index_item_metadata_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_item_metadata_on_organization_id ON public.item_metadata USING btree (organization_id);


--
-- Name: index_item_metadata_on_owner_type_and_owner_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_item_metadata_on_owner_type_and_owner_id ON public.item_metadata USING btree (owner_type, owner_id);


--
-- Name: index_item_metadata_on_value; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_item_metadata_on_value ON public.item_metadata USING gin (value);


--
-- Name: index_lifetime_usages_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_lifetime_usages_on_organization_id ON public.lifetime_usages USING btree (organization_id);


--
-- Name: index_lifetime_usages_on_recalculate_current_usage; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_lifetime_usages_on_recalculate_current_usage ON public.lifetime_usages USING btree (recalculate_current_usage) WHERE ((deleted_at IS NULL) AND (recalculate_current_usage = true));


--
-- Name: index_lifetime_usages_on_recalculate_invoiced_usage; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_lifetime_usages_on_recalculate_invoiced_usage ON public.lifetime_usages USING btree (recalculate_invoiced_usage) WHERE ((deleted_at IS NULL) AND (recalculate_invoiced_usage = true));


--
-- Name: index_lifetime_usages_on_subscription_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_lifetime_usages_on_subscription_id ON public.lifetime_usages USING btree (subscription_id);


--
-- Name: index_membership_roles_by_membership_and_organization; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_membership_roles_by_membership_and_organization ON public.membership_roles USING btree (membership_id, organization_id) WHERE (deleted_at IS NULL);


--
-- Name: index_membership_roles_on_role_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_membership_roles_on_role_id ON public.membership_roles USING btree (role_id);


--
-- Name: index_membership_roles_uniqueness; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_membership_roles_uniqueness ON public.membership_roles USING btree (membership_id, role_id) WHERE (deleted_at IS NULL);


--
-- Name: index_memberships_by_id_and_organization; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_memberships_by_id_and_organization ON public.memberships USING btree (id, organization_id);


--
-- Name: index_memberships_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_memberships_on_organization_id ON public.memberships USING btree (organization_id);


--
-- Name: index_memberships_on_user_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_memberships_on_user_id ON public.memberships USING btree (user_id);


--
-- Name: index_memberships_on_user_id_and_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_memberships_on_user_id_and_organization_id ON public.memberships USING btree (user_id, organization_id) WHERE (revoked_at IS NULL);


--
-- Name: index_order_forms_on_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_order_forms_on_customer_id ON public.order_forms USING btree (customer_id);


--
-- Name: index_order_forms_on_organization_id_and_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_order_forms_on_organization_id_and_created_at ON public.order_forms USING btree (organization_id, created_at);


--
-- Name: index_order_forms_on_organization_id_and_expires_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_order_forms_on_organization_id_and_expires_at ON public.order_forms USING btree (organization_id, expires_at);


--
-- Name: index_order_forms_on_organization_id_and_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_order_forms_on_organization_id_and_status ON public.order_forms USING btree (organization_id, status);


--
-- Name: index_order_forms_on_quote_version_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_order_forms_on_quote_version_id ON public.order_forms USING btree (quote_version_id);


--
-- Name: index_orders_on_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_orders_on_customer_id ON public.orders USING btree (customer_id);


--
-- Name: index_orders_on_execute_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_orders_on_execute_at ON public.orders USING btree (execute_at) WHERE ((status = 'created'::public.order_status) AND (execute_at IS NOT NULL));


--
-- Name: index_orders_on_order_form_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_orders_on_order_form_id ON public.orders USING btree (order_form_id);


--
-- Name: index_orders_on_organization_id_and_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_orders_on_organization_id_and_created_at ON public.orders USING btree (organization_id, created_at);


--
-- Name: index_orders_on_organization_id_and_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_orders_on_organization_id_and_status ON public.orders USING btree (organization_id, status);


--
-- Name: index_organizations_on_api_key; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_organizations_on_api_key ON public.organizations USING btree (api_key);


--
-- Name: index_organizations_on_hmac_key; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_organizations_on_hmac_key ON public.organizations USING btree (hmac_key);


--
-- Name: index_organizations_on_slug; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_organizations_on_slug ON public.organizations USING btree (slug);


--
-- Name: index_password_resets_on_token; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_password_resets_on_token ON public.password_resets USING btree (token);


--
-- Name: index_password_resets_on_user_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_password_resets_on_user_id ON public.password_resets USING btree (user_id);


--
-- Name: index_payment_intents_on_invoice_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payment_intents_on_invoice_id ON public.payment_intents USING btree (invoice_id);


--
-- Name: index_payment_intents_on_invoice_id_and_status; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_payment_intents_on_invoice_id_and_status ON public.payment_intents USING btree (invoice_id, status) WHERE (status = 0);


--
-- Name: index_payment_intents_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payment_intents_on_organization_id ON public.payment_intents USING btree (organization_id);


--
-- Name: index_payment_methods_on_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payment_methods_on_customer_id ON public.payment_methods USING btree (customer_id);


--
-- Name: index_payment_methods_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payment_methods_on_organization_id ON public.payment_methods USING btree (organization_id);


--
-- Name: index_payment_methods_on_payment_provider_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payment_methods_on_payment_provider_customer_id ON public.payment_methods USING btree (payment_provider_customer_id);


--
-- Name: index_payment_methods_on_payment_provider_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payment_methods_on_payment_provider_id ON public.payment_methods USING btree (payment_provider_id);


--
-- Name: index_payment_methods_on_provider_customer_and_provider_method; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_payment_methods_on_provider_customer_and_provider_method ON public.payment_methods USING btree (payment_provider_customer_id, provider_method_id) WHERE (deleted_at IS NULL);


--
-- Name: index_payment_methods_on_provider_method_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payment_methods_on_provider_method_type ON public.payment_methods USING btree (provider_method_type);


--
-- Name: index_payment_provider_customers_on_customer_id_and_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_payment_provider_customers_on_customer_id_and_code ON public.payment_provider_customers USING btree (customer_id, code) WHERE (deleted_at IS NULL);


--
-- Name: index_payment_provider_customers_on_customer_id_and_type; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_payment_provider_customers_on_customer_id_and_type ON public.payment_provider_customers USING btree (customer_id, type) WHERE (deleted_at IS NULL);


--
-- Name: index_payment_provider_customers_on_customer_id_default; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_payment_provider_customers_on_customer_id_default ON public.payment_provider_customers USING btree (customer_id) WHERE (is_default AND (deleted_at IS NULL));


--
-- Name: index_payment_provider_customers_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payment_provider_customers_on_organization_id ON public.payment_provider_customers USING btree (organization_id);


--
-- Name: index_payment_provider_customers_on_payment_provider_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payment_provider_customers_on_payment_provider_id ON public.payment_provider_customers USING btree (payment_provider_id);


--
-- Name: index_payment_provider_customers_on_provider_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payment_provider_customers_on_provider_customer_id ON public.payment_provider_customers USING btree (provider_customer_id);


--
-- Name: index_payment_providers_on_code_and_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_payment_providers_on_code_and_organization_id ON public.payment_providers USING btree (code, organization_id) WHERE (deleted_at IS NULL);


--
-- Name: index_payment_providers_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payment_providers_on_organization_id ON public.payment_providers USING btree (organization_id);


--
-- Name: index_payment_receipts_on_billing_entity_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payment_receipts_on_billing_entity_id ON public.payment_receipts USING btree (billing_entity_id);


--
-- Name: index_payment_receipts_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payment_receipts_on_organization_id ON public.payment_receipts USING btree (organization_id);


--
-- Name: index_payment_receipts_on_payment_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_payment_receipts_on_payment_id ON public.payment_receipts USING btree (payment_id);


--
-- Name: index_payment_requests_on_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payment_requests_on_customer_id ON public.payment_requests USING btree (customer_id);


--
-- Name: index_payment_requests_on_dunning_campaign_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payment_requests_on_dunning_campaign_id ON public.payment_requests USING btree (dunning_campaign_id);


--
-- Name: index_payment_requests_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payment_requests_on_organization_id ON public.payment_requests USING btree (organization_id);


--
-- Name: index_payments_by_cursor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payments_by_cursor ON public.payments USING btree (organization_id, created_at DESC, id);


--
-- Name: index_payments_on_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payments_on_customer_id ON public.payments USING btree (customer_id);


--
-- Name: index_payments_on_invoice_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payments_on_invoice_id ON public.payments USING btree (invoice_id);


--
-- Name: index_payments_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payments_on_organization_id ON public.payments USING btree (organization_id);


--
-- Name: index_payments_on_organization_id_reference_gin_trgm_ops; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payments_on_organization_id_reference_gin_trgm_ops ON public.payments USING gin (organization_id, reference public.gin_trgm_ops);


--
-- Name: index_payments_on_payable_id_and_payable_type; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_payments_on_payable_id_and_payable_type ON public.payments USING btree (payable_id, payable_type) WHERE ((payable_payment_status = ANY (ARRAY['pending'::public.payment_payable_payment_status, 'processing'::public.payment_payable_payment_status])) AND (payment_type = 'provider'::public.payment_type));


--
-- Name: index_payments_on_payable_id_and_payable_type_and_error_code; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payments_on_payable_id_and_payable_type_and_error_code ON public.payments USING btree (payable_id, payable_type, error_code);


--
-- Name: index_payments_on_payable_type_and_payable_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payments_on_payable_type_and_payable_id ON public.payments USING btree (payable_type, payable_id);


--
-- Name: index_payments_on_payment_method_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payments_on_payment_method_id ON public.payments USING btree (payment_method_id);


--
-- Name: index_payments_on_payment_provider_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payments_on_payment_provider_customer_id ON public.payments USING btree (payment_provider_customer_id);


--
-- Name: index_payments_on_payment_provider_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payments_on_payment_provider_id ON public.payments USING btree (payment_provider_id);


--
-- Name: index_payments_on_payment_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_payments_on_payment_type ON public.payments USING btree (payment_type);


--
-- Name: index_payments_on_provider_payment_id_and_payment_provider_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_payments_on_provider_payment_id_and_payment_provider_id ON public.payments USING btree (provider_payment_id, payment_provider_id) WHERE (provider_payment_id IS NOT NULL);


--
-- Name: index_pending_active_subscriptions_on_plan_id_and_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_pending_active_subscriptions_on_plan_id_and_status ON public.subscriptions USING btree (plan_id, status) WHERE (status = ANY (ARRAY[0, 1]));


--
-- Name: index_pending_vies_checks_on_billing_entity_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_pending_vies_checks_on_billing_entity_id ON public.pending_vies_checks USING btree (billing_entity_id);


--
-- Name: index_pending_vies_checks_on_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_pending_vies_checks_on_customer_id ON public.pending_vies_checks USING btree (customer_id);


--
-- Name: index_pending_vies_checks_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_pending_vies_checks_on_organization_id ON public.pending_vies_checks USING btree (organization_id);


--
-- Name: index_plan_rate_cards_by_cursor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_plan_rate_cards_by_cursor ON public.plan_rate_cards USING btree (catalog_plan_id, created_at DESC, id DESC) WHERE (deleted_at IS NULL);


--
-- Name: index_plan_rate_cards_on_catalog_plan_id_and_rate_card_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_plan_rate_cards_on_catalog_plan_id_and_rate_card_id ON public.plan_rate_cards USING btree (catalog_plan_id, rate_card_id) WHERE (deleted_at IS NULL);


--
-- Name: index_plan_rate_cards_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_plan_rate_cards_on_deleted_at ON public.plan_rate_cards USING btree (deleted_at);


--
-- Name: index_plan_rate_cards_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_plan_rate_cards_on_organization_id ON public.plan_rate_cards USING btree (organization_id);


--
-- Name: index_plan_rate_cards_on_rate_card_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_plan_rate_cards_on_rate_card_id ON public.plan_rate_cards USING btree (rate_card_id);


--
-- Name: index_plans_on_bill_fixed_charges_monthly; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_plans_on_bill_fixed_charges_monthly ON public.plans USING btree (bill_fixed_charges_monthly) WHERE ((deleted_at IS NULL) AND (bill_fixed_charges_monthly IS TRUE));


--
-- Name: index_plans_on_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_plans_on_created_at ON public.plans USING btree (created_at);


--
-- Name: index_plans_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_plans_on_deleted_at ON public.plans USING btree (deleted_at);


--
-- Name: index_plans_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_plans_on_organization_id ON public.plans USING btree (organization_id);


--
-- Name: index_plans_on_organization_id_and_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_plans_on_organization_id_and_code ON public.plans USING btree (organization_id, code) WHERE ((deleted_at IS NULL) AND (parent_id IS NULL));


--
-- Name: index_plans_on_organization_id_code_gin_trgm_ops; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_plans_on_organization_id_code_gin_trgm_ops ON public.plans USING gin (organization_id, code public.gin_trgm_ops) WHERE (deleted_at IS NULL);


--
-- Name: index_plans_on_organization_id_name_gin_trgm_ops; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_plans_on_organization_id_name_gin_trgm_ops ON public.plans USING gin (organization_id, name public.gin_trgm_ops) WHERE (deleted_at IS NULL);


--
-- Name: index_plans_on_parent_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_plans_on_parent_id ON public.plans USING btree (parent_id);


--
-- Name: index_plans_taxes_on_catalog_plan_id_and_tax_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_plans_taxes_on_catalog_plan_id_and_tax_id ON public.plans_taxes USING btree (catalog_plan_id, tax_id);


--
-- Name: index_plans_taxes_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_plans_taxes_on_organization_id ON public.plans_taxes USING btree (organization_id);


--
-- Name: index_plans_taxes_on_plan_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_plans_taxes_on_plan_id ON public.plans_taxes USING btree (plan_id);


--
-- Name: index_plans_taxes_on_plan_id_and_tax_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_plans_taxes_on_plan_id_and_tax_id ON public.plans_taxes USING btree (plan_id, tax_id);


--
-- Name: index_plans_taxes_on_tax_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_plans_taxes_on_tax_id ON public.plans_taxes USING btree (tax_id);


--
-- Name: index_presentation_breakdowns_on_fee_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_presentation_breakdowns_on_fee_id ON public.presentation_breakdowns USING btree (fee_id);


--
-- Name: index_presentation_breakdowns_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_presentation_breakdowns_on_organization_id ON public.presentation_breakdowns USING btree (organization_id);


--
-- Name: index_pricing_unit_usages_on_fee_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_pricing_unit_usages_on_fee_id ON public.pricing_unit_usages USING btree (fee_id);


--
-- Name: index_pricing_unit_usages_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_pricing_unit_usages_on_organization_id ON public.pricing_unit_usages USING btree (organization_id);


--
-- Name: index_pricing_unit_usages_on_pricing_unit_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_pricing_unit_usages_on_pricing_unit_id ON public.pricing_unit_usages USING btree (pricing_unit_id);


--
-- Name: index_pricing_units_on_code_and_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_pricing_units_on_code_and_organization_id ON public.pricing_units USING btree (code, organization_id);


--
-- Name: index_pricing_units_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_pricing_units_on_organization_id ON public.pricing_units USING btree (organization_id);


--
-- Name: index_product_categories_by_cursor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_product_categories_by_cursor ON public.product_categories USING btree (organization_id, created_at DESC, id DESC) WHERE (deleted_at IS NULL);


--
-- Name: index_product_categories_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_product_categories_on_deleted_at ON public.product_categories USING btree (deleted_at);


--
-- Name: index_product_categories_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_product_categories_on_organization_id ON public.product_categories USING btree (organization_id);


--
-- Name: index_product_categories_on_organization_id_and_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_product_categories_on_organization_id_and_code ON public.product_categories USING btree (organization_id, code) WHERE (deleted_at IS NULL);


--
-- Name: index_product_filter_values_on_billable_metric_filter_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_product_filter_values_on_billable_metric_filter_id ON public.product_filter_values USING btree (billable_metric_filter_id);


--
-- Name: index_product_filter_values_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_product_filter_values_on_deleted_at ON public.product_filter_values USING btree (deleted_at);


--
-- Name: index_product_filter_values_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_product_filter_values_on_organization_id ON public.product_filter_values USING btree (organization_id);


--
-- Name: index_product_filter_values_on_product_filter_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_product_filter_values_on_product_filter_id ON public.product_filter_values USING btree (product_filter_id);


--
-- Name: index_product_filters_by_cursor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_product_filters_by_cursor ON public.product_filters USING btree (product_id, created_at DESC, id DESC) WHERE (deleted_at IS NULL);


--
-- Name: index_product_filters_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_product_filters_on_deleted_at ON public.product_filters USING btree (deleted_at);


--
-- Name: index_product_filters_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_product_filters_on_organization_id ON public.product_filters USING btree (organization_id);


--
-- Name: index_product_filters_on_product_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_product_filters_on_product_id ON public.product_filters USING btree (product_id);


--
-- Name: index_product_filters_on_product_id_and_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_product_filters_on_product_id_and_code ON public.product_filters USING btree (product_id, code) WHERE (deleted_at IS NULL);


--
-- Name: index_products_by_cursor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_products_by_cursor ON public.products USING btree (organization_id, created_at DESC, id DESC) WHERE (deleted_at IS NULL);


--
-- Name: index_products_on_add_on_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_products_on_add_on_id ON public.products USING btree (add_on_id);


--
-- Name: index_products_on_billable_metric_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_products_on_billable_metric_id ON public.products USING btree (billable_metric_id);


--
-- Name: index_products_on_charge_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_products_on_charge_id ON public.products USING btree (charge_id);


--
-- Name: index_products_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_products_on_deleted_at ON public.products USING btree (deleted_at);


--
-- Name: index_products_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_products_on_organization_id ON public.products USING btree (organization_id);


--
-- Name: index_products_on_organization_id_and_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_products_on_organization_id_and_code ON public.products USING btree (organization_id, code) WHERE (deleted_at IS NULL);


--
-- Name: index_products_on_product_category_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_products_on_product_category_id ON public.products USING btree (product_category_id);


--
-- Name: index_quantified_events_on_billable_metric_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_quantified_events_on_billable_metric_id ON public.quantified_events USING btree (billable_metric_id);


--
-- Name: index_quantified_events_on_charge_filter_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_quantified_events_on_charge_filter_id ON public.quantified_events USING btree (charge_filter_id);


--
-- Name: index_quantified_events_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_quantified_events_on_deleted_at ON public.quantified_events USING btree (deleted_at);


--
-- Name: index_quantified_events_on_external_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_quantified_events_on_external_id ON public.quantified_events USING btree (external_id);


--
-- Name: index_quantified_events_on_group_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_quantified_events_on_group_id ON public.quantified_events USING btree (group_id);


--
-- Name: index_quantified_events_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_quantified_events_on_organization_id ON public.quantified_events USING btree (organization_id);


--
-- Name: index_quote_owners_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_quote_owners_on_organization_id ON public.quote_owners USING btree (organization_id);


--
-- Name: index_quote_owners_on_user_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_quote_owners_on_user_id ON public.quote_owners USING btree (user_id);


--
-- Name: index_quote_versions_on_billing_entity_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_quote_versions_on_billing_entity_id ON public.quote_versions USING btree (billing_entity_id);


--
-- Name: index_quote_versions_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_quote_versions_on_organization_id ON public.quote_versions USING btree (organization_id);


--
-- Name: index_quote_versions_on_quote_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_quote_versions_on_quote_id ON public.quote_versions USING btree (quote_id);


--
-- Name: index_quotes_on_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_quotes_on_customer_id ON public.quotes USING btree (customer_id);


--
-- Name: index_quotes_on_subscription_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_quotes_on_subscription_id ON public.quotes USING btree (subscription_id);


--
-- Name: index_rate_card_rates_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_rate_card_rates_on_deleted_at ON public.rate_card_rates USING btree (deleted_at);


--
-- Name: index_rate_card_rates_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_rate_card_rates_on_organization_id ON public.rate_card_rates USING btree (organization_id);


--
-- Name: index_rate_card_rates_on_rate_card_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_rate_card_rates_on_rate_card_id ON public.rate_card_rates USING btree (rate_card_id);


--
-- Name: index_rate_card_rates_on_rate_card_id_and_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_rate_card_rates_on_rate_card_id_and_code ON public.rate_card_rates USING btree (rate_card_id, code) WHERE (deleted_at IS NULL);


--
-- Name: index_rate_card_rates_on_rate_card_id_and_effective_from; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_rate_card_rates_on_rate_card_id_and_effective_from ON public.rate_card_rates USING btree (rate_card_id, effective_from) WHERE (deleted_at IS NULL);


--
-- Name: index_rate_cards_by_cursor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_rate_cards_by_cursor ON public.rate_cards USING btree (organization_id, created_at DESC, id DESC) WHERE (deleted_at IS NULL);


--
-- Name: index_rate_cards_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_rate_cards_on_deleted_at ON public.rate_cards USING btree (deleted_at);


--
-- Name: index_rate_cards_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_rate_cards_on_organization_id ON public.rate_cards USING btree (organization_id);


--
-- Name: index_rate_cards_on_organization_id_and_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_rate_cards_on_organization_id_and_code ON public.rate_cards USING btree (organization_id, code) WHERE (deleted_at IS NULL);


--
-- Name: index_rate_cards_on_product_filter_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_rate_cards_on_product_filter_id ON public.rate_cards USING btree (product_filter_id);


--
-- Name: index_rate_cards_on_product_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_rate_cards_on_product_id ON public.rate_cards USING btree (product_id);


--
-- Name: index_rate_cards_taxes_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_rate_cards_taxes_on_organization_id ON public.rate_cards_taxes USING btree (organization_id);


--
-- Name: index_rate_cards_taxes_on_rate_card_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_rate_cards_taxes_on_rate_card_id ON public.rate_cards_taxes USING btree (rate_card_id);


--
-- Name: index_rate_cards_taxes_on_rate_card_id_and_tax_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_rate_cards_taxes_on_rate_card_id_and_tax_id ON public.rate_cards_taxes USING btree (rate_card_id, tax_id);


--
-- Name: index_rate_cards_taxes_on_tax_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_rate_cards_taxes_on_tax_id ON public.rate_cards_taxes USING btree (tax_id);


--
-- Name: index_rate_overrides_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_rate_overrides_on_deleted_at ON public.rate_overrides USING btree (deleted_at);


--
-- Name: index_rate_overrides_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_rate_overrides_on_organization_id ON public.rate_overrides USING btree (organization_id);


--
-- Name: index_rate_phases_on_contract_rate_card_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_rate_phases_on_contract_rate_card_id ON public.rate_phases USING btree (contract_rate_card_id);


--
-- Name: index_rate_phases_on_contract_rate_card_id_and_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_rate_phases_on_contract_rate_card_id_and_code ON public.rate_phases USING btree (contract_rate_card_id, code) WHERE ((contract_rate_card_id IS NOT NULL) AND (deleted_at IS NULL));


--
-- Name: index_rate_phases_on_contract_rate_card_id_and_position; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_rate_phases_on_contract_rate_card_id_and_position ON public.rate_phases USING btree (contract_rate_card_id, "position") WHERE ((contract_rate_card_id IS NOT NULL) AND (deleted_at IS NULL));


--
-- Name: index_rate_phases_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_rate_phases_on_deleted_at ON public.rate_phases USING btree (deleted_at);


--
-- Name: index_rate_phases_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_rate_phases_on_organization_id ON public.rate_phases USING btree (organization_id);


--
-- Name: index_rate_phases_on_plan_rate_card_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_rate_phases_on_plan_rate_card_id ON public.rate_phases USING btree (plan_rate_card_id);


--
-- Name: index_rate_phases_on_plan_rate_card_id_and_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_rate_phases_on_plan_rate_card_id_and_code ON public.rate_phases USING btree (plan_rate_card_id, code) WHERE ((plan_rate_card_id IS NOT NULL) AND (deleted_at IS NULL));


--
-- Name: index_rate_phases_on_plan_rate_card_id_and_position; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_rate_phases_on_plan_rate_card_id_and_position ON public.rate_phases USING btree (plan_rate_card_id, "position") WHERE ((plan_rate_card_id IS NOT NULL) AND (deleted_at IS NULL));


--
-- Name: index_rate_phases_on_rate_override_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_rate_phases_on_rate_override_id ON public.rate_phases USING btree (rate_override_id) WHERE ((rate_override_id IS NOT NULL) AND (deleted_at IS NULL));


--
-- Name: index_record_deletions_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_record_deletions_on_deleted_at ON public.record_deletions USING btree (deleted_at);


--
-- Name: index_record_deletions_on_organization_id_and_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_record_deletions_on_organization_id_and_deleted_at ON public.record_deletions USING btree (organization_id, deleted_at);


--
-- Name: index_record_deletions_on_updated_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_record_deletions_on_updated_at ON public.record_deletions USING btree (updated_at);


--
-- Name: index_recurring_transaction_rules_on_expiration_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_recurring_transaction_rules_on_expiration_at ON public.recurring_transaction_rules USING btree (expiration_at);


--
-- Name: index_recurring_transaction_rules_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_recurring_transaction_rules_on_organization_id ON public.recurring_transaction_rules USING btree (organization_id);


--
-- Name: index_recurring_transaction_rules_on_payment_method_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_recurring_transaction_rules_on_payment_method_id ON public.recurring_transaction_rules USING btree (payment_method_id);


--
-- Name: index_recurring_transaction_rules_on_started_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_recurring_transaction_rules_on_started_at ON public.recurring_transaction_rules USING btree (started_at);


--
-- Name: index_recurring_transaction_rules_on_wallet_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_recurring_transaction_rules_on_wallet_id ON public.recurring_transaction_rules USING btree (wallet_id);


--
-- Name: index_refunds_on_credit_note_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_refunds_on_credit_note_id ON public.refunds USING btree (credit_note_id);


--
-- Name: index_refunds_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_refunds_on_organization_id ON public.refunds USING btree (organization_id);


--
-- Name: index_refunds_on_payment_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_refunds_on_payment_id ON public.refunds USING btree (payment_id);


--
-- Name: index_refunds_on_payment_provider_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_refunds_on_payment_provider_customer_id ON public.refunds USING btree (payment_provider_customer_id);


--
-- Name: index_refunds_on_payment_provider_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_refunds_on_payment_provider_id ON public.refunds USING btree (payment_provider_id);


--
-- Name: index_refunds_on_refundable; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_refunds_on_refundable ON public.refunds USING btree (refundable_type, refundable_id);


--
-- Name: index_roles_by_code_per_organization; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_roles_by_code_per_organization ON public.roles USING btree (organization_id NULLS FIRST, code) WHERE (deleted_at IS NULL);


--
-- Name: index_roles_by_unique_admin; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_roles_by_unique_admin ON public.roles USING btree (admin) WHERE (admin AND (deleted_at IS NULL));


--
-- Name: index_roles_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_roles_on_organization_id ON public.roles USING btree (organization_id);


--
-- Name: index_rtr_invoice_custom_sections_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_rtr_invoice_custom_sections_unique ON public.recurring_transaction_rules_invoice_custom_sections USING btree (recurring_transaction_rule_id, invoice_custom_section_id);


--
-- Name: index_search_quantified_events; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_search_quantified_events ON public.quantified_events USING btree (organization_id, external_subscription_id, billable_metric_id);


--
-- Name: index_streaming_destinations_on_event_types; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_streaming_destinations_on_event_types ON public.streaming_destinations USING gin (event_types);


--
-- Name: index_streaming_destinations_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_streaming_destinations_on_organization_id ON public.streaming_destinations USING btree (organization_id);


--
-- Name: index_sub_fc_units_overrides_on_sub_id_and_fc_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_sub_fc_units_overrides_on_sub_id_and_fc_id ON public.subscription_fixed_charge_units_overrides USING btree (subscription_id, fixed_charge_id) WHERE (deleted_at IS NULL);


--
-- Name: index_subscription_activation_rules_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_subscription_activation_rules_on_organization_id ON public.subscription_activation_rules USING btree (organization_id);


--
-- Name: index_subscription_fixed_charge_units_overrides_on_deleted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_subscription_fixed_charge_units_overrides_on_deleted_at ON public.subscription_fixed_charge_units_overrides USING btree (deleted_at);


--
-- Name: index_subscriptions_invoice_custom_sections_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_subscriptions_invoice_custom_sections_on_organization_id ON public.subscriptions_invoice_custom_sections USING btree (organization_id);


--
-- Name: index_subscriptions_invoice_custom_sections_on_subscription_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_subscriptions_invoice_custom_sections_on_subscription_id ON public.subscriptions_invoice_custom_sections USING btree (subscription_id);


--
-- Name: index_subscriptions_invoice_custom_sections_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_subscriptions_invoice_custom_sections_unique ON public.subscriptions_invoice_custom_sections USING btree (subscription_id, invoice_custom_section_id);


--
-- Name: index_subscriptions_on_billing_entity_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_subscriptions_on_billing_entity_id ON public.subscriptions USING btree (billing_entity_id);


--
-- Name: index_subscriptions_on_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_subscriptions_on_customer_id ON public.subscriptions USING btree (customer_id);


--
-- Name: index_subscriptions_on_ending_at_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_subscriptions_on_ending_at_active ON public.subscriptions USING btree (ending_at) WHERE ((status = 1) AND (ending_at IS NOT NULL));


--
-- Name: index_subscriptions_on_external_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_subscriptions_on_external_id ON public.subscriptions USING btree (external_id);


--
-- Name: index_subscriptions_on_last_received_event_on; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_subscriptions_on_last_received_event_on ON public.subscriptions USING btree (last_received_event_on);


--
-- Name: index_subscriptions_on_last_received_event_on_null; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_subscriptions_on_last_received_event_on_null ON public.subscriptions USING btree (id) WHERE (last_received_event_on IS NULL);


--
-- Name: index_subscriptions_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_subscriptions_on_organization_id ON public.subscriptions USING btree (organization_id);


--
-- Name: index_subscriptions_on_organization_id_name_gin_trgm_ops; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_subscriptions_on_organization_id_name_gin_trgm_ops ON public.subscriptions USING gin (organization_id, name public.gin_trgm_ops);


--
-- Name: index_subscriptions_on_payment_method_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_subscriptions_on_payment_method_id ON public.subscriptions USING btree (payment_method_id);


--
-- Name: index_subscriptions_on_plan_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_subscriptions_on_plan_id ON public.subscriptions USING btree (plan_id);


--
-- Name: index_subscriptions_on_previous_subscription_id_and_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_subscriptions_on_previous_subscription_id_and_status ON public.subscriptions USING btree (previous_subscription_id, status);


--
-- Name: index_subscriptions_on_started_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_subscriptions_on_started_at ON public.subscriptions USING btree (started_at);


--
-- Name: index_subscriptions_on_started_at_and_ending_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_subscriptions_on_started_at_and_ending_at ON public.subscriptions USING btree (started_at, ending_at);


--
-- Name: index_subscriptions_on_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_subscriptions_on_status ON public.subscriptions USING btree (status);


--
-- Name: index_taxes_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_taxes_on_organization_id ON public.taxes USING btree (organization_id);


--
-- Name: index_uniq_invoice_subscriptions_on_charges_from_to_datetime; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_uniq_invoice_subscriptions_on_charges_from_to_datetime ON public.invoice_subscriptions USING btree (subscription_id, charges_from_datetime, charges_to_datetime) WHERE ((created_at >= '2023-06-09 00:00:00'::timestamp without time zone) AND (recurring IS TRUE) AND (regenerated_invoice_id IS NULL));


--
-- Name: index_uniq_invoice_subscriptions_on_fixed_charges_boundaries; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_uniq_invoice_subscriptions_on_fixed_charges_boundaries ON public.invoice_subscriptions USING btree (subscription_id, fixed_charges_from_datetime, fixed_charges_to_datetime) WHERE ((fixed_charges_from_datetime IS NOT NULL) AND (recurring IS TRUE) AND (regenerated_invoice_id IS NULL));


--
-- Name: index_uniq_wallet_code_per_customer; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_uniq_wallet_code_per_customer ON public.wallets USING btree (customer_id, code) WHERE (status = 0);


--
-- Name: index_unique_applied_to_organization_per_organization; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_unique_applied_to_organization_per_organization ON public.dunning_campaigns USING btree (organization_id) WHERE ((applied_to_organization = true) AND (deleted_at IS NULL));


--
-- Name: index_unique_order_forms_on_organization_number; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_unique_order_forms_on_organization_number ON public.order_forms USING btree (organization_id, number);


--
-- Name: index_unique_order_forms_on_organization_sequential_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_unique_order_forms_on_organization_sequential_id ON public.order_forms USING btree (organization_id, sequential_id);


--
-- Name: index_unique_orders_on_organization_number; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_unique_orders_on_organization_number ON public.orders USING btree (organization_id, number);


--
-- Name: index_unique_orders_on_organization_sequential_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_unique_orders_on_organization_sequential_id ON public.orders USING btree (organization_id, sequential_id);


--
-- Name: index_unique_quote_owners_on_quote_user; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_unique_quote_owners_on_quote_user ON public.quote_owners USING btree (quote_id, user_id);


--
-- Name: index_unique_quote_versions_on_quote_active_status; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_unique_quote_versions_on_quote_active_status ON public.quote_versions USING btree (quote_id) WHERE (status = ANY (ARRAY['draft'::public.quote_status, 'approved'::public.quote_status]));


--
-- Name: index_unique_quote_versions_on_quote_sequential_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_unique_quote_versions_on_quote_sequential_id ON public.quote_versions USING btree (quote_id, sequential_id);


--
-- Name: index_unique_quotes_on_organization_number; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_unique_quotes_on_organization_number ON public.quotes USING btree (organization_id, number);


--
-- Name: index_unique_quotes_on_organization_sequential_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_unique_quotes_on_organization_sequential_id ON public.quotes USING btree (organization_id, sequential_id);


--
-- Name: index_unique_starting_invoice_subscription; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_unique_starting_invoice_subscription ON public.invoice_subscriptions USING btree (subscription_id, invoicing_reason) WHERE ((invoicing_reason = 'subscription_starting'::public.subscription_invoicing_reason) AND (regenerated_invoice_id IS NULL));


--
-- Name: index_unique_terminating_invoice_subscription; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_unique_terminating_invoice_subscription ON public.invoice_subscriptions USING btree (subscription_id, invoicing_reason) WHERE ((invoicing_reason = 'subscription_terminating'::public.subscription_invoicing_reason) AND (regenerated_invoice_id IS NULL));


--
-- Name: index_unique_transaction_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_unique_transaction_id ON public.events USING btree (organization_id, external_subscription_id, transaction_id);


--
-- Name: index_usage_attribution_types_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_usage_attribution_types_on_organization_id ON public.usage_attribution_types USING btree (organization_id);


--
-- Name: index_usage_attribution_types_on_organization_id_and_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_usage_attribution_types_on_organization_id_and_code ON public.usage_attribution_types USING btree (organization_id, code) WHERE (deleted_at IS NULL);


--
-- Name: index_usage_attribution_types_on_parent_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_usage_attribution_types_on_parent_id ON public.usage_attribution_types USING btree (parent_id);


--
-- Name: index_usage_attribution_values_on_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_usage_attribution_values_on_customer_id ON public.usage_attribution_values USING btree (customer_id);


--
-- Name: index_usage_attribution_values_on_customer_id_and_last_seen_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_usage_attribution_values_on_customer_id_and_last_seen_at ON public.usage_attribution_values USING btree (customer_id, last_seen_at);


--
-- Name: index_usage_attribution_values_on_customer_type_and_value; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_usage_attribution_values_on_customer_type_and_value ON public.usage_attribution_values USING btree (customer_id, usage_attribution_type_id, value);


--
-- Name: index_usage_attribution_values_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_usage_attribution_values_on_organization_id ON public.usage_attribution_values USING btree (organization_id);


--
-- Name: index_usage_attribution_values_on_parent_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_usage_attribution_values_on_parent_id ON public.usage_attribution_values USING btree (parent_id);


--
-- Name: index_usage_attribution_values_on_usage_attribution_type_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_usage_attribution_values_on_usage_attribution_type_id ON public.usage_attribution_values USING btree (usage_attribution_type_id);


--
-- Name: index_usage_monitoring_alert_thresholds_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_usage_monitoring_alert_thresholds_on_organization_id ON public.usage_monitoring_alert_thresholds USING btree (organization_id);


--
-- Name: index_usage_monitoring_alerts_on_billable_metric_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_usage_monitoring_alerts_on_billable_metric_id ON public.usage_monitoring_alerts USING btree (billable_metric_id);


--
-- Name: index_usage_monitoring_alerts_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_usage_monitoring_alerts_on_organization_id ON public.usage_monitoring_alerts USING btree (organization_id);


--
-- Name: index_usage_monitoring_alerts_on_subscription_external_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_usage_monitoring_alerts_on_subscription_external_id ON public.usage_monitoring_alerts USING btree (subscription_external_id);


--
-- Name: index_usage_monitoring_alerts_on_wallet_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_usage_monitoring_alerts_on_wallet_id ON public.usage_monitoring_alerts USING btree (wallet_id);


--
-- Name: index_usage_monitoring_triggered_alerts_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_usage_monitoring_triggered_alerts_on_organization_id ON public.usage_monitoring_triggered_alerts USING btree (organization_id);


--
-- Name: index_usage_monitoring_triggered_alerts_on_subscription_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_usage_monitoring_triggered_alerts_on_subscription_id ON public.usage_monitoring_triggered_alerts USING btree (subscription_id);


--
-- Name: index_usage_monitoring_triggered_alerts_on_wallet_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_usage_monitoring_triggered_alerts_on_wallet_id ON public.usage_monitoring_triggered_alerts USING btree (wallet_id);


--
-- Name: index_usage_thresholds_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_usage_thresholds_on_organization_id ON public.usage_thresholds USING btree (organization_id);


--
-- Name: index_usage_thresholds_on_plan_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_usage_thresholds_on_plan_id ON public.usage_thresholds USING btree (plan_id);


--
-- Name: index_usage_thresholds_on_subscription_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_usage_thresholds_on_subscription_id ON public.usage_thresholds USING btree (subscription_id);


--
-- Name: index_user_devices_on_user_id_and_fingerprint; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_user_devices_on_user_id_and_fingerprint ON public.user_devices USING btree (user_id, fingerprint);


--
-- Name: index_versions_on_item_type_and_item_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_versions_on_item_type_and_item_id ON public.versions USING btree (item_type, item_id);


--
-- Name: index_wallet_targets_on_billable_metric_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_wallet_targets_on_billable_metric_id ON public.wallet_targets USING btree (billable_metric_id);


--
-- Name: index_wallet_targets_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_wallet_targets_on_organization_id ON public.wallet_targets USING btree (organization_id);


--
-- Name: index_wallet_targets_on_wallet_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_wallet_targets_on_wallet_id ON public.wallet_targets USING btree (wallet_id);


--
-- Name: index_wallet_transaction_consumptions_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_wallet_transaction_consumptions_on_organization_id ON public.wallet_transaction_consumptions USING btree (organization_id);


--
-- Name: index_wallet_transactions_on_billing_entity_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_wallet_transactions_on_billing_entity_id ON public.wallet_transactions USING btree (billing_entity_id);


--
-- Name: index_wallet_transactions_on_credit_note_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_wallet_transactions_on_credit_note_id ON public.wallet_transactions USING btree (credit_note_id);


--
-- Name: index_wallet_transactions_on_invoice_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_wallet_transactions_on_invoice_id ON public.wallet_transactions USING btree (invoice_id);


--
-- Name: index_wallet_transactions_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_wallet_transactions_on_organization_id ON public.wallet_transactions USING btree (organization_id);


--
-- Name: index_wallet_transactions_on_payment_method_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_wallet_transactions_on_payment_method_id ON public.wallet_transactions USING btree (payment_method_id);


--
-- Name: index_wallet_transactions_on_voided_invoice_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_wallet_transactions_on_voided_invoice_id ON public.wallet_transactions USING btree (voided_invoice_id);


--
-- Name: index_wallet_transactions_on_wallet_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_wallet_transactions_on_wallet_id ON public.wallet_transactions USING btree (wallet_id);


--
-- Name: index_wallets_invoice_custom_sections_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_wallets_invoice_custom_sections_on_organization_id ON public.wallets_invoice_custom_sections USING btree (organization_id);


--
-- Name: index_wallets_invoice_custom_sections_on_wallet_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_wallets_invoice_custom_sections_on_wallet_id ON public.wallets_invoice_custom_sections USING btree (wallet_id);


--
-- Name: index_wallets_invoice_custom_sections_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_wallets_invoice_custom_sections_unique ON public.wallets_invoice_custom_sections USING btree (wallet_id, invoice_custom_section_id);


--
-- Name: index_wallets_on_billing_entity_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_wallets_on_billing_entity_id ON public.wallets USING btree (billing_entity_id);


--
-- Name: index_wallets_on_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_wallets_on_customer_id ON public.wallets USING btree (customer_id);


--
-- Name: index_wallets_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_wallets_on_organization_id ON public.wallets USING btree (organization_id);


--
-- Name: index_wallets_on_organization_id_and_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_wallets_on_organization_id_and_customer_id ON public.wallets USING btree (organization_id, customer_id);


--
-- Name: index_wallets_on_payment_method_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_wallets_on_payment_method_id ON public.wallets USING btree (payment_method_id);


--
-- Name: index_wallets_on_ready_to_be_refreshed; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_wallets_on_ready_to_be_refreshed ON public.wallets USING btree (ready_to_be_refreshed) WHERE ready_to_be_refreshed;


--
-- Name: index_webhook_endpoints_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_webhook_endpoints_on_organization_id ON public.webhook_endpoints USING btree (organization_id);


--
-- Name: index_webhook_endpoints_on_webhook_url_and_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_webhook_endpoints_on_webhook_url_and_organization_id ON public.webhook_endpoints USING btree (webhook_url, organization_id);


--
-- Name: index_webhooks_for_query; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_webhooks_for_query ON public.webhooks USING btree (organization_id, webhook_endpoint_id, webhook_type, updated_at);


--
-- Name: index_webhooks_on_endpoint_and_timestamps; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_webhooks_on_endpoint_and_timestamps ON public.webhooks USING btree (webhook_endpoint_id, updated_at, created_at);


--
-- Name: index_webhooks_on_endpoint_status_and_timestamps; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_webhooks_on_endpoint_status_and_timestamps ON public.webhooks USING btree (webhook_endpoint_id, status, updated_at);


--
-- Name: index_webhooks_on_object_type_and_object_id_and_webhook_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_webhooks_on_object_type_and_object_id_and_webhook_type ON public.webhooks USING btree (object_type, object_id, webhook_type);


--
-- Name: index_webhooks_on_organization_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_webhooks_on_organization_id ON public.webhooks USING btree (organization_id);


--
-- Name: index_webhooks_on_updated_at_for_cleanup; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_webhooks_on_updated_at_for_cleanup ON public.webhooks USING btree (updated_at) INCLUDE (id);


--
-- Name: index_webhooks_on_webhook_endpoint_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX index_webhooks_on_webhook_endpoint_id ON public.webhooks USING btree (webhook_endpoint_id);


--
-- Name: index_wt_invoice_custom_sections_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX index_wt_invoice_custom_sections_unique ON public.wallet_transactions_invoice_custom_sections USING btree (wallet_transaction_id, invoice_custom_section_id);


--
-- Name: unique_default_payment_method_per_customer; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX unique_default_payment_method_per_customer ON public.payment_methods USING btree (customer_id) WHERE ((is_default = true) AND (deleted_at IS NULL));


--
-- Name: billable_metrics_grouped_charges _RETURN; Type: RULE; Schema: public; Owner: -
--

CREATE OR REPLACE VIEW public.billable_metrics_grouped_charges AS
 SELECT billable_metrics.organization_id,
    billable_metrics.code,
    billable_metrics.aggregation_type,
    billable_metrics.field_name,
    charges.plan_id,
    charges.id AS charge_id,
    charges.pay_in_advance,
        CASE
            WHEN (charges.charge_model = 0) THEN (charges.properties -> 'grouped_by'::text)
            ELSE NULL::jsonb
        END AS grouped_by,
    charge_filters.id AS charge_filter_id,
    json_object_agg(billable_metric_filters.key, COALESCE(charge_filter_values."values", '{}'::character varying[]) ORDER BY billable_metric_filters.key) FILTER (WHERE (billable_metric_filters.key IS NOT NULL)) AS filters,
        CASE
            WHEN (charges.charge_model = 0) THEN (charge_filters.properties -> 'grouped_by'::text)
            ELSE NULL::jsonb
        END AS filters_grouped_by
   FROM ((((public.billable_metrics
     JOIN public.charges ON ((charges.billable_metric_id = billable_metrics.id)))
     LEFT JOIN public.charge_filters ON ((charge_filters.charge_id = charges.id)))
     LEFT JOIN public.charge_filter_values ON ((charge_filter_values.charge_filter_id = charge_filters.id)))
     LEFT JOIN public.billable_metric_filters ON ((charge_filter_values.billable_metric_filter_id = billable_metric_filters.id)))
  WHERE ((billable_metrics.deleted_at IS NULL) AND (charges.deleted_at IS NULL) AND (charge_filters.deleted_at IS NULL) AND (charge_filter_values.deleted_at IS NULL) AND (billable_metric_filters.deleted_at IS NULL))
  GROUP BY billable_metrics.organization_id, billable_metrics.code, billable_metrics.aggregation_type, billable_metrics.field_name, charges.plan_id, charges.id, charge_filters.id;


--
-- Name: flat_filters _RETURN; Type: RULE; Schema: public; Owner: -
--

CREATE OR REPLACE VIEW public.flat_filters AS
 SELECT billable_metrics.organization_id,
    billable_metrics.code AS billable_metric_code,
    charges.plan_id,
    charges.id AS charge_id,
    charges.updated_at AS charge_updated_at,
    charge_filters.id AS charge_filter_id,
    charge_filters.updated_at AS charge_filter_updated_at,
        CASE
            WHEN (charge_filters.id IS NOT NULL) THEN jsonb_object_agg(COALESCE(billable_metric_filters.key, ''::character varying),
            CASE
                WHEN ((charge_filter_values."values")::text[] && ARRAY['__ALL_FILTER_VALUES__'::text]) THEN billable_metric_filters."values"
                ELSE charge_filter_values."values"
            END)
            ELSE NULL::jsonb
        END AS filters,
    (COALESCE(charge_filters.properties, charges.properties) -> 'pricing_group_keys'::text) AS pricing_group_keys,
    charges.pay_in_advance,
    charges.accepts_target_wallet
   FROM ((((public.billable_metrics
     JOIN public.charges ON ((charges.billable_metric_id = billable_metrics.id)))
     LEFT JOIN public.charge_filters ON (((charge_filters.charge_id = charges.id) AND (charge_filters.deleted_at IS NULL))))
     LEFT JOIN public.charge_filter_values ON (((charge_filter_values.charge_filter_id = charge_filters.id) AND (charge_filter_values.deleted_at IS NULL))))
     LEFT JOIN public.billable_metric_filters ON (((billable_metric_filters.id = charge_filter_values.billable_metric_filter_id) AND (billable_metric_filters.deleted_at IS NULL))))
  WHERE ((billable_metrics.deleted_at IS NULL) AND (charges.deleted_at IS NULL))
  GROUP BY billable_metrics.organization_id, billable_metrics.code, charges.plan_id, charges.id, charges.updated_at, charge_filters.id, charge_filters.updated_at;


--
-- Name: payment_receipts before_payment_receipt_insert; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER before_payment_receipt_insert BEFORE INSERT ON public.payment_receipts FOR EACH ROW EXECUTE FUNCTION public.set_payment_receipt_number();


--
-- Name: roles ensure_consistency; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER ensure_consistency BEFORE UPDATE ON public.roles FOR EACH ROW EXECUTE FUNCTION public.ensure_role_consistency();


--
-- Name: credit_notes_taxes record_deletions_on_credit_notes_taxes; Type: TRIGGER; Schema: public; Owner: -
--

CREATE CONSTRAINT TRIGGER record_deletions_on_credit_notes_taxes AFTER DELETE ON public.credit_notes_taxes DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.record_deletion();


--
-- Name: fees record_deletions_on_fees; Type: TRIGGER; Schema: public; Owner: -
--

CREATE CONSTRAINT TRIGGER record_deletions_on_fees AFTER DELETE ON public.fees DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.record_deletion();


--
-- Name: fees_taxes record_deletions_on_fees_taxes; Type: TRIGGER; Schema: public; Owner: -
--

CREATE CONSTRAINT TRIGGER record_deletions_on_fees_taxes AFTER DELETE ON public.fees_taxes DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.record_deletion();


--
-- Name: invoice_subscriptions record_deletions_on_invoice_subscriptions; Type: TRIGGER; Schema: public; Owner: -
--

CREATE CONSTRAINT TRIGGER record_deletions_on_invoice_subscriptions AFTER DELETE ON public.invoice_subscriptions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.record_deletion();


--
-- Name: invoices_taxes record_deletions_on_invoices_taxes; Type: TRIGGER; Schema: public; Owner: -
--

CREATE CONSTRAINT TRIGGER record_deletions_on_invoices_taxes AFTER DELETE ON public.invoices_taxes DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.record_deletion();


--
-- Name: fees fk_fees_contract_rate_card_contract; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fees
    ADD CONSTRAINT fk_fees_contract_rate_card_contract FOREIGN KEY (contract_rate_card_id, contract_id) REFERENCES public.contract_rate_cards(id, contract_id);


--
-- Name: payment_methods fk_rails_00e7a45b0b; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_methods
    ADD CONSTRAINT fk_rails_00e7a45b0b FOREIGN KEY (payment_provider_id) REFERENCES public.payment_providers(id);


--
-- Name: pending_vies_checks fk_rails_019e2289e5; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pending_vies_checks
    ADD CONSTRAINT fk_rails_019e2289e5 FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: wallet_transactions fk_rails_01a4c0c7db; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallet_transactions
    ADD CONSTRAINT fk_rails_01a4c0c7db FOREIGN KEY (invoice_id) REFERENCES public.invoices(id);


--
-- Name: invoice_settlements fk_rails_04388258ff; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_settlements
    ADD CONSTRAINT fk_rails_04388258ff FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: subscription_fixed_charge_units_overrides fk_rails_0480ef4ad3; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_fixed_charge_units_overrides
    ADD CONSTRAINT fk_rails_0480ef4ad3 FOREIGN KEY (fixed_charge_id) REFERENCES public.fixed_charges(id);


--
-- Name: product_filters fk_rails_050342349f; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_filters
    ADD CONSTRAINT fk_rails_050342349f FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: invoices fk_rails_06b7046ec3; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT fk_rails_06b7046ec3 FOREIGN KEY (billing_entity_id) REFERENCES public.billing_entities(id);


--
-- Name: billing_entities_taxes fk_rails_07b21049f2; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_entities_taxes
    ADD CONSTRAINT fk_rails_07b21049f2 FOREIGN KEY (billing_entity_id) REFERENCES public.billing_entities(id);


--
-- Name: fees fk_rails_085d1cc97b; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fees
    ADD CONSTRAINT fk_rails_085d1cc97b FOREIGN KEY (charge_id) REFERENCES public.charges(id);


--
-- Name: add_ons_taxes fk_rails_08dfe87131; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.add_ons_taxes
    ADD CONSTRAINT fk_rails_08dfe87131 FOREIGN KEY (add_on_id) REFERENCES public.add_ons(id);


--
-- Name: fees fk_rails_0934890b24; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fees
    ADD CONSTRAINT fk_rails_0934890b24 FOREIGN KEY (billing_entity_id) REFERENCES public.billing_entities(id);


--
-- Name: usage_monitoring_triggered_alerts fk_rails_0baa7bd751; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_monitoring_triggered_alerts
    ADD CONSTRAINT fk_rails_0baa7bd751 FOREIGN KEY (subscription_id) REFERENCES public.subscriptions(id);


--
-- Name: coupon_targets fk_rails_0bb6dcc01f; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coupon_targets
    ADD CONSTRAINT fk_rails_0bb6dcc01f FOREIGN KEY (coupon_id) REFERENCES public.coupons(id);


--
-- Name: entitlement_entitlements fk_rails_0c9773c34d; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entitlement_entitlements
    ADD CONSTRAINT fk_rails_0c9773c34d FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: customers_taxes fk_rails_0d2be3d72c; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers_taxes
    ADD CONSTRAINT fk_rails_0d2be3d72c FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: invoices fk_rails_0d349e632f; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT fk_rails_0d349e632f FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: ai_conversations fk_rails_0da056ac92; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_conversations
    ADD CONSTRAINT fk_rails_0da056ac92 FOREIGN KEY (membership_id) REFERENCES public.memberships(id);


--
-- Name: integration_customers fk_rails_0e464363cb; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integration_customers
    ADD CONSTRAINT fk_rails_0e464363cb FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: billing_segments fk_rails_0f69bb4d01; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_segments
    ADD CONSTRAINT fk_rails_0f69bb4d01 FOREIGN KEY (rate_card_rate_id) REFERENCES public.rate_card_rates(id);


--
-- Name: integration_mappings fk_rails_0f762162b0; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integration_mappings
    ADD CONSTRAINT fk_rails_0f762162b0 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: usage_monitoring_triggered_alerts fk_rails_0f807322b1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_monitoring_triggered_alerts
    ADD CONSTRAINT fk_rails_0f807322b1 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: fees_taxes fk_rails_103e187859; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fees_taxes
    ADD CONSTRAINT fk_rails_103e187859 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: applied_invoice_custom_sections fk_rails_10428ecad2; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.applied_invoice_custom_sections
    ADD CONSTRAINT fk_rails_10428ecad2 FOREIGN KEY (invoice_id) REFERENCES public.invoices(id);


--
-- Name: quote_versions fk_rails_10ee148d0d; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quote_versions
    ADD CONSTRAINT fk_rails_10ee148d0d FOREIGN KEY (quote_id) REFERENCES public.quotes(id);


--
-- Name: entitlement_subscription_feature_removals fk_rails_123667657c; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entitlement_subscription_feature_removals
    ADD CONSTRAINT fk_rails_123667657c FOREIGN KEY (subscription_id) REFERENCES public.subscriptions(id);


--
-- Name: daily_usages fk_rails_12d29bc654; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.daily_usages
    ADD CONSTRAINT fk_rails_12d29bc654 FOREIGN KEY (subscription_id) REFERENCES public.subscriptions(id);


--
-- Name: rate_cards_taxes fk_rails_133ae613c4; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rate_cards_taxes
    ADD CONSTRAINT fk_rails_133ae613c4 FOREIGN KEY (tax_id) REFERENCES public.taxes(id);


--
-- Name: invoices_taxes fk_rails_142809fee1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices_taxes
    ADD CONSTRAINT fk_rails_142809fee1 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: coupon_targets fk_rails_1454058c96; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coupon_targets
    ADD CONSTRAINT fk_rails_1454058c96 FOREIGN KEY (billable_metric_id) REFERENCES public.billable_metrics(id);


--
-- Name: invoice_subscriptions fk_rails_150139409e; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_subscriptions
    ADD CONSTRAINT fk_rails_150139409e FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: entitlement_entitlements fk_rails_173327f0dc; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entitlement_entitlements
    ADD CONSTRAINT fk_rails_173327f0dc FOREIGN KEY (subscription_id) REFERENCES public.subscriptions(id);


--
-- Name: quote_owners fk_rails_1811b32fcd; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quote_owners
    ADD CONSTRAINT fk_rails_1811b32fcd FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: coupon_targets fk_rails_189f2a3949; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coupon_targets
    ADD CONSTRAINT fk_rails_189f2a3949 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: usage_attribution_types fk_rails_18b43f689c; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_attribution_types
    ADD CONSTRAINT fk_rails_18b43f689c FOREIGN KEY (parent_id) REFERENCES public.usage_attribution_types(id);


--
-- Name: customer_metadata fk_rails_195153290d; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_metadata
    ADD CONSTRAINT fk_rails_195153290d FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: catalog_plans fk_rails_19759667f5; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.catalog_plans
    ADD CONSTRAINT fk_rails_19759667f5 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: billing_entities_invoice_custom_sections fk_rails_19c47827ba; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_entities_invoice_custom_sections
    ADD CONSTRAINT fk_rails_19c47827ba FOREIGN KEY (invoice_custom_section_id) REFERENCES public.invoice_custom_sections(id);


--
-- Name: rate_phases fk_rails_1c069c1c44; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rate_phases
    ADD CONSTRAINT fk_rails_1c069c1c44 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: applied_usage_thresholds fk_rails_1d112bf8a0; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.applied_usage_thresholds
    ADD CONSTRAINT fk_rails_1d112bf8a0 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: credits fk_rails_1db0057d9b; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.credits
    ADD CONSTRAINT fk_rails_1db0057d9b FOREIGN KEY (applied_coupon_id) REFERENCES public.applied_coupons(id);


--
-- Name: webhooks fk_rails_20cc0de4c7; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.webhooks
    ADD CONSTRAINT fk_rails_20cc0de4c7 FOREIGN KEY (webhook_endpoint_id) REFERENCES public.webhook_endpoints(id);


--
-- Name: customers_invoice_custom_sections fk_rails_20f157fa49; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers_invoice_custom_sections
    ADD CONSTRAINT fk_rails_20f157fa49 FOREIGN KEY (invoice_custom_section_id) REFERENCES public.invoice_custom_sections(id);


--
-- Name: plans fk_rails_216ac8a975; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plans
    ADD CONSTRAINT fk_rails_216ac8a975 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: webhook_endpoints fk_rails_21808fa528; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.webhook_endpoints
    ADD CONSTRAINT fk_rails_21808fa528 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: plan_rate_cards fk_rails_21cbfc2122; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_rate_cards
    ADD CONSTRAINT fk_rails_21cbfc2122 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: cached_aggregations fk_rails_21eb389927; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cached_aggregations
    ADD CONSTRAINT fk_rails_21eb389927 FOREIGN KEY (group_id) REFERENCES public.groups(id);


--
-- Name: commitments_taxes fk_rails_2259c88f26; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.commitments_taxes
    ADD CONSTRAINT fk_rails_2259c88f26 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: invoices_taxes fk_rails_22af6c6d28; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices_taxes
    ADD CONSTRAINT fk_rails_22af6c6d28 FOREIGN KEY (invoice_id) REFERENCES public.invoices(id);


--
-- Name: applied_pricing_units fk_rails_22bb2c0770; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.applied_pricing_units
    ADD CONSTRAINT fk_rails_22bb2c0770 FOREIGN KEY (pricing_unit_id) REFERENCES public.pricing_units(id);


--
-- Name: taxes fk_rails_23975f5a47; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.taxes
    ADD CONSTRAINT fk_rails_23975f5a47 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: invoice_connections fk_rails_246e13679b; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_connections
    ADD CONSTRAINT fk_rails_246e13679b FOREIGN KEY (integration_customer_id) REFERENCES public.integration_customers(id);


--
-- Name: invoices_payment_requests fk_rails_2496c105ed; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices_payment_requests
    ADD CONSTRAINT fk_rails_2496c105ed FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: credit_notes_taxes fk_rails_25232a0ec3; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.credit_notes_taxes
    ADD CONSTRAINT fk_rails_25232a0ec3 FOREIGN KEY (credit_note_id) REFERENCES public.credit_notes(id);


--
-- Name: refunds fk_rails_25267b0e17; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.refunds
    ADD CONSTRAINT fk_rails_25267b0e17 FOREIGN KEY (payment_id) REFERENCES public.payments(id);


--
-- Name: invoice_settlements fk_rails_2539663124; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_settlements
    ADD CONSTRAINT fk_rails_2539663124 FOREIGN KEY (source_payment_id) REFERENCES public.payments(id);


--
-- Name: adjusted_fees fk_rails_2561c00887; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.adjusted_fees
    ADD CONSTRAINT fk_rails_2561c00887 FOREIGN KEY (fee_id) REFERENCES public.fees(id);


--
-- Name: fees fk_rails_257af22645; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fees
    ADD CONSTRAINT fk_rails_257af22645 FOREIGN KEY (true_up_parent_fee_id) REFERENCES public.fees(id);


--
-- Name: billing_entities_taxes fk_rails_268c288aaa; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_entities_taxes
    ADD CONSTRAINT fk_rails_268c288aaa FOREIGN KEY (tax_id) REFERENCES public.taxes(id);


--
-- Name: payment_providers fk_rails_26be2f764d; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_providers
    ADD CONSTRAINT fk_rails_26be2f764d FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: charge_filters fk_rails_27b55b8574; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.charge_filters
    ADD CONSTRAINT fk_rails_27b55b8574 FOREIGN KEY (charge_id) REFERENCES public.charges(id);


--
-- Name: wallets fk_rails_28077d4aa2; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallets
    ADD CONSTRAINT fk_rails_28077d4aa2 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: billing_object_connections fk_rails_287ffb7123; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_object_connections
    ADD CONSTRAINT fk_rails_287ffb7123 FOREIGN KEY (integration_customer_id) REFERENCES public.integration_customers(id);


--
-- Name: usage_thresholds fk_rails_2908dd8de5; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_thresholds
    ADD CONSTRAINT fk_rails_2908dd8de5 FOREIGN KEY (subscription_id) REFERENCES public.subscriptions(id);


--
-- Name: record_deletions fk_rails_29b1e26ad3; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.record_deletions
    ADD CONSTRAINT fk_rails_29b1e26ad3 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: wallets fk_rails_2b35eef34b; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallets
    ADD CONSTRAINT fk_rails_2b35eef34b FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: ai_conversations fk_rails_2c06a74f41; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_conversations
    ADD CONSTRAINT fk_rails_2c06a74f41 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: product_filters fk_rails_2c40f5b85c; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_filters
    ADD CONSTRAINT fk_rails_2c40f5b85c FOREIGN KEY (product_id) REFERENCES public.products(id);


--
-- Name: billing_segments fk_rails_2cad4a133e; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_segments
    ADD CONSTRAINT fk_rails_2cad4a133e FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: refunds fk_rails_2dc6171f57; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.refunds
    ADD CONSTRAINT fk_rails_2dc6171f57 FOREIGN KEY (payment_provider_id) REFERENCES public.payment_providers(id);


--
-- Name: fees fk_rails_2ea4db3a4c; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fees
    ADD CONSTRAINT fk_rails_2ea4db3a4c FOREIGN KEY (subscription_id) REFERENCES public.subscriptions(id);


--
-- Name: payment_requests fk_rails_2fb2147151; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_requests
    ADD CONSTRAINT fk_rails_2fb2147151 FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: credits fk_rails_2fd7ee65e6; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.credits
    ADD CONSTRAINT fk_rails_2fd7ee65e6 FOREIGN KEY (progressive_billing_invoice_id) REFERENCES public.invoices(id);


--
-- Name: invoice_settlements fk_rails_2ffeff5323; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_settlements
    ADD CONSTRAINT fk_rails_2ffeff5323 FOREIGN KEY (billing_entity_id) REFERENCES public.billing_entities(id);


--
-- Name: wallets_invoice_custom_sections fk_rails_3092f5f2e0; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallets_invoice_custom_sections
    ADD CONSTRAINT fk_rails_3092f5f2e0 FOREIGN KEY (invoice_custom_section_id) REFERENCES public.invoice_custom_sections(id);


--
-- Name: invoices fk_rails_309d3a4412; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT fk_rails_309d3a4412 FOREIGN KEY (payment_method_id) REFERENCES public.payment_methods(id);


--
-- Name: credits fk_rails_310fcb3585; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.credits
    ADD CONSTRAINT fk_rails_310fcb3585 FOREIGN KEY (credit_note_id) REFERENCES public.credit_notes(id);


--
-- Name: contracts fk_rails_31b4ac35c4; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contracts
    ADD CONSTRAINT fk_rails_31b4ac35c4 FOREIGN KEY (payment_method_id) REFERENCES public.payment_methods(id);


--
-- Name: billing_segments fk_rails_322fa429f9; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_segments
    ADD CONSTRAINT fk_rails_322fa429f9 FOREIGN KEY (contract_rate_card_id) REFERENCES public.contract_rate_cards(id);


--
-- Name: payment_requests fk_rails_32600e5a72; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_requests
    ADD CONSTRAINT fk_rails_32600e5a72 FOREIGN KEY (dunning_campaign_id) REFERENCES public.dunning_campaigns(id);


--
-- Name: customers_taxes fk_rails_33d169382f; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers_taxes
    ADD CONSTRAINT fk_rails_33d169382f FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: lifetime_usages fk_rails_348acbd245; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lifetime_usages
    ADD CONSTRAINT fk_rails_348acbd245 FOREIGN KEY (subscription_id) REFERENCES public.subscriptions(id);


--
-- Name: fees fk_rails_34ab152115; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fees
    ADD CONSTRAINT fk_rails_34ab152115 FOREIGN KEY (applied_add_on_id) REFERENCES public.applied_add_ons(id);


--
-- Name: wallets_invoice_custom_sections fk_rails_34b4e489e6; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallets_invoice_custom_sections
    ADD CONSTRAINT fk_rails_34b4e489e6 FOREIGN KEY (wallet_id) REFERENCES public.wallets(id);


--
-- Name: groups fk_rails_34b5ee1894; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.groups
    ADD CONSTRAINT fk_rails_34b5ee1894 FOREIGN KEY (billable_metric_id) REFERENCES public.billable_metrics(id) ON DELETE CASCADE;


--
-- Name: charge_filter_values fk_rails_3640b4a66a; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.charge_filter_values
    ADD CONSTRAINT fk_rails_3640b4a66a FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: subscriptions fk_rails_364213cc3e; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT fk_rails_364213cc3e FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: inbound_webhooks fk_rails_36cda06530; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inbound_webhooks
    ADD CONSTRAINT fk_rails_36cda06530 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: product_filter_values fk_rails_36e9122b3e; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_filter_values
    ADD CONSTRAINT fk_rails_36e9122b3e FOREIGN KEY (billable_metric_filter_id) REFERENCES public.billable_metric_filters(id);


--
-- Name: products fk_rails_37c75ac37a; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT fk_rails_37c75ac37a FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: quantified_events fk_rails_3926855f12; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quantified_events
    ADD CONSTRAINT fk_rails_3926855f12 FOREIGN KEY (group_id) REFERENCES public.groups(id);


--
-- Name: invoices fk_rails_3a303bf667; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT fk_rails_3a303bf667 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: payments fk_rails_3ab959bfc4; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT fk_rails_3ab959bfc4 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: group_properties fk_rails_3acf9e789c; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.group_properties
    ADD CONSTRAINT fk_rails_3acf9e789c FOREIGN KEY (charge_id) REFERENCES public.charges(id) ON DELETE CASCADE;


--
-- Name: invoice_settlements fk_rails_3b7dad8e9c; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_settlements
    ADD CONSTRAINT fk_rails_3b7dad8e9c FOREIGN KEY (target_invoice_id) REFERENCES public.invoices(id);


--
-- Name: wallet_transaction_consumptions fk_rails_3c786cd3e3; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallet_transaction_consumptions
    ADD CONSTRAINT fk_rails_3c786cd3e3 FOREIGN KEY (inbound_wallet_transaction_id) REFERENCES public.wallet_transactions(id);


--
-- Name: daily_usages fk_rails_3c7c3920c0; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.daily_usages
    ADD CONSTRAINT fk_rails_3c7c3920c0 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: charges fk_rails_3cfe1d68d7; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.charges
    ADD CONSTRAINT fk_rails_3cfe1d68d7 FOREIGN KEY (parent_id) REFERENCES public.charges(id);


--
-- Name: integration_collection_mappings fk_rails_3d568ff9de; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integration_collection_mappings
    ADD CONSTRAINT fk_rails_3d568ff9de FOREIGN KEY (integration_id) REFERENCES public.integrations(id);


--
-- Name: orders fk_rails_3dad120da9; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT fk_rails_3dad120da9 FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: entitlement_privileges fk_rails_3e4df02771; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entitlement_privileges
    ADD CONSTRAINT fk_rails_3e4df02771 FOREIGN KEY (entitlement_feature_id) REFERENCES public.entitlement_features(id);


--
-- Name: invoices_payment_requests fk_rails_3ec3563cf3; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices_payment_requests
    ADD CONSTRAINT fk_rails_3ec3563cf3 FOREIGN KEY (invoice_id) REFERENCES public.invoices(id);


--
-- Name: refunds fk_rails_3f7be5debc; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.refunds
    ADD CONSTRAINT fk_rails_3f7be5debc FOREIGN KEY (credit_note_id) REFERENCES public.credit_notes(id);


--
-- Name: charges_taxes fk_rails_3ff27d7624; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.charges_taxes
    ADD CONSTRAINT fk_rails_3ff27d7624 FOREIGN KEY (tax_id) REFERENCES public.taxes(id);


--
-- Name: credit_notes fk_rails_41088c7d45; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.credit_notes
    ADD CONSTRAINT fk_rails_41088c7d45 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: credit_notes fk_rails_4117574b51; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.credit_notes
    ADD CONSTRAINT fk_rails_4117574b51 FOREIGN KEY (invoice_id) REFERENCES public.invoices(id);


--
-- Name: quote_owners fk_rails_45230f8485; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quote_owners
    ADD CONSTRAINT fk_rails_45230f8485 FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: contract_rate_cards fk_rails_457dfe353b; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contract_rate_cards
    ADD CONSTRAINT fk_rails_457dfe353b FOREIGN KEY (rate_card_id) REFERENCES public.rate_cards(id);


--
-- Name: integration_items fk_rails_47d8081062; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integration_items
    ADD CONSTRAINT fk_rails_47d8081062 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: webhooks fk_rails_49212d501e; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.webhooks
    ADD CONSTRAINT fk_rails_49212d501e FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: charges fk_rails_4934f27a06; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.charges
    ADD CONSTRAINT fk_rails_4934f27a06 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: recurring_transaction_rules_invoice_custom_sections fk_rails_49fcc221b0; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recurring_transaction_rules_invoice_custom_sections
    ADD CONSTRAINT fk_rails_49fcc221b0 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: billing_entities fk_rails_4aa58496c3; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_entities
    ADD CONSTRAINT fk_rails_4aa58496c3 FOREIGN KEY (applied_dunning_campaign_id) REFERENCES public.dunning_campaigns(id) ON DELETE SET NULL;


--
-- Name: fees fk_rails_4cadfc14f3; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fees
    ADD CONSTRAINT fk_rails_4cadfc14f3 FOREIGN KEY (rate_card_rate_id) REFERENCES public.rate_card_rates(id);


--
-- Name: order_forms fk_rails_4ed54bfec0; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.order_forms
    ADD CONSTRAINT fk_rails_4ed54bfec0 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: rate_cards fk_rails_4f7ffc3e03; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rate_cards
    ADD CONSTRAINT fk_rails_4f7ffc3e03 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: wallets fk_rails_4ff087c52e; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallets
    ADD CONSTRAINT fk_rails_4ff087c52e FOREIGN KEY (billing_entity_id) REFERENCES public.billing_entities(id);


--
-- Name: payment_provider_customers fk_rails_50d46d3679; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_provider_customers
    ADD CONSTRAINT fk_rails_50d46d3679 FOREIGN KEY (payment_provider_id) REFERENCES public.payment_providers(id);


--
-- Name: billable_metric_filters fk_rails_51077e7c0e; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billable_metric_filters
    ADD CONSTRAINT fk_rails_51077e7c0e FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: products fk_rails_512c4da634; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT fk_rails_512c4da634 FOREIGN KEY (add_on_id) REFERENCES public.add_ons(id);


--
-- Name: commitments fk_rails_51ac39a0c6; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.commitments
    ADD CONSTRAINT fk_rails_51ac39a0c6 FOREIGN KEY (plan_id) REFERENCES public.plans(id);


--
-- Name: credits fk_rails_521b5240ed; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.credits
    ADD CONSTRAINT fk_rails_521b5240ed FOREIGN KEY (invoice_id) REFERENCES public.invoices(id);


--
-- Name: usage_attribution_values fk_rails_521c8108bf; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_attribution_values
    ADD CONSTRAINT fk_rails_521c8108bf FOREIGN KEY (parent_id) REFERENCES public.usage_attribution_values(id);


--
-- Name: recurring_transaction_rules fk_rails_52370612ae; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recurring_transaction_rules
    ADD CONSTRAINT fk_rails_52370612ae FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: password_resets fk_rails_526379cd99; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_resets
    ADD CONSTRAINT fk_rails_526379cd99 FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: applied_usage_thresholds fk_rails_52b72c9b0e; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.applied_usage_thresholds
    ADD CONSTRAINT fk_rails_52b72c9b0e FOREIGN KEY (invoice_id) REFERENCES public.invoices(id);


--
-- Name: product_filter_values fk_rails_530173fa8d; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_filter_values
    ADD CONSTRAINT fk_rails_530173fa8d FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: entitlement_entitlement_values fk_rails_533b639bac; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entitlement_entitlement_values
    ADD CONSTRAINT fk_rails_533b639bac FOREIGN KEY (entitlement_entitlement_id) REFERENCES public.entitlement_entitlements(id);


--
-- Name: entitlement_entitlements fk_rails_54c6fe0506; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entitlement_entitlements
    ADD CONSTRAINT fk_rails_54c6fe0506 FOREIGN KEY (catalog_plan_id) REFERENCES public.catalog_plans(id);


--
-- Name: cs_admin_audit_logs fk_rails_559c8fe04c; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cs_admin_audit_logs
    ADD CONSTRAINT fk_rails_559c8fe04c FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: credits fk_rails_5628a713de; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.credits
    ADD CONSTRAINT fk_rails_5628a713de FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: subscriptions fk_rails_56b3626631; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT fk_rails_56b3626631 FOREIGN KEY (billing_entity_id) REFERENCES public.billing_entities(id);


--
-- Name: charges_taxes fk_rails_56b7167125; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.charges_taxes
    ADD CONSTRAINT fk_rails_56b7167125 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: customers fk_rails_58234c715e; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT fk_rails_58234c715e FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: billing_segments fk_rails_59357d1f82; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_segments
    ADD CONSTRAINT fk_rails_59357d1f82 FOREIGN KEY (invoice_id) REFERENCES public.invoices(id);


--
-- Name: data_exports fk_rails_5a43da571b; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.data_exports
    ADD CONSTRAINT fk_rails_5a43da571b FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: invoice_settlements fk_rails_5a4b906a16; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_settlements
    ADD CONSTRAINT fk_rails_5a4b906a16 FOREIGN KEY (source_credit_note_id) REFERENCES public.credit_notes(id);


--
-- Name: add_ons_taxes fk_rails_5ade8984b1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.add_ons_taxes
    ADD CONSTRAINT fk_rails_5ade8984b1 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: quotes fk_rails_5bb40a7bae; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quotes
    ADD CONSTRAINT fk_rails_5bb40a7bae FOREIGN KEY (subscription_id) REFERENCES public.subscriptions(id);


--
-- Name: error_details fk_rails_5c21eece29; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.error_details
    ADD CONSTRAINT fk_rails_5c21eece29 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: payment_receipts fk_rails_5c2e0b6d34; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_receipts
    ADD CONSTRAINT fk_rails_5c2e0b6d34 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: credit_note_items fk_rails_5cb2f24c3d; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.credit_note_items
    ADD CONSTRAINT fk_rails_5cb2f24c3d FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: credit_notes fk_rails_5cb67dee79; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.credit_notes
    ADD CONSTRAINT fk_rails_5cb67dee79 FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: fixed_charges fk_rails_5e06da3c18; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fixed_charges
    ADD CONSTRAINT fk_rails_5e06da3c18 FOREIGN KEY (plan_id) REFERENCES public.plans(id);


--
-- Name: invoice_connections fk_rails_5eaad62ac0; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_connections
    ADD CONSTRAINT fk_rails_5eaad62ac0 FOREIGN KEY (invoice_id) REFERENCES public.invoices(id);


--
-- Name: recurring_transaction_rules fk_rails_5efea6fe31; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recurring_transaction_rules
    ADD CONSTRAINT fk_rails_5efea6fe31 FOREIGN KEY (payment_method_id) REFERENCES public.payment_methods(id);


--
-- Name: fees fk_rails_6023b3f2dd; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fees
    ADD CONSTRAINT fk_rails_6023b3f2dd FOREIGN KEY (add_on_id) REFERENCES public.add_ons(id);


--
-- Name: cs_admin_audit_logs fk_rails_602bbeefa1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cs_admin_audit_logs
    ADD CONSTRAINT fk_rails_602bbeefa1 FOREIGN KEY (rollback_of_id) REFERENCES public.cs_admin_audit_logs(id);


--
-- Name: credit_notes_taxes fk_rails_626209b8d2; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.credit_notes_taxes
    ADD CONSTRAINT fk_rails_626209b8d2 FOREIGN KEY (tax_id) REFERENCES public.taxes(id);


--
-- Name: order_forms fk_rails_6298debfc7; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.order_forms
    ADD CONSTRAINT fk_rails_6298debfc7 FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: payments fk_rails_62d18ea517; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT fk_rails_62d18ea517 FOREIGN KEY (invoice_id) REFERENCES public.invoices(id);


--
-- Name: invoice_metadata fk_rails_63683837a2; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_metadata
    ADD CONSTRAINT fk_rails_63683837a2 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: applied_invoice_custom_sections fk_rails_63ac282e70; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.applied_invoice_custom_sections
    ADD CONSTRAINT fk_rails_63ac282e70 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: pricing_unit_usages fk_rails_63ca8e33c5; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pricing_unit_usages
    ADD CONSTRAINT fk_rails_63ca8e33c5 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: subscriptions fk_rails_63d3df128b; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT fk_rails_63d3df128b FOREIGN KEY (plan_id) REFERENCES public.plans(id);


--
-- Name: memberships fk_rails_64267aab58; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.memberships
    ADD CONSTRAINT fk_rails_64267aab58 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: membership_roles fk_rails_65053e240e; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.membership_roles
    ADD CONSTRAINT fk_rails_65053e240e FOREIGN KEY (role_id) REFERENCES public.roles(id);


--
-- Name: integration_collection_mappings fk_rails_650fccfc41; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integration_collection_mappings
    ADD CONSTRAINT fk_rails_650fccfc41 FOREIGN KEY (billing_entity_id) REFERENCES public.billing_entities(id) ON DELETE CASCADE;


--
-- Name: billing_entities_taxes fk_rails_651eadaaa4; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_entities_taxes
    ADD CONSTRAINT fk_rails_651eadaaa4 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: fixed_charges_taxes fk_rails_665ae33492; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fixed_charges_taxes
    ADD CONSTRAINT fk_rails_665ae33492 FOREIGN KEY (tax_id) REFERENCES public.taxes(id);


--
-- Name: subscriptions fk_rails_66eb6b32c1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT fk_rails_66eb6b32c1 FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: integration_resources fk_rails_67d4eb3c92; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integration_resources
    ADD CONSTRAINT fk_rails_67d4eb3c92 FOREIGN KEY (integration_id) REFERENCES public.integrations(id);


--
-- Name: customers_invoice_custom_sections fk_rails_68754484c0; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers_invoice_custom_sections
    ADD CONSTRAINT fk_rails_68754484c0 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: billing_entities_invoice_custom_sections fk_rails_699cd1384f; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_entities_invoice_custom_sections
    ADD CONSTRAINT fk_rails_699cd1384f FOREIGN KEY (billing_entity_id) REFERENCES public.billing_entities(id);


--
-- Name: fees fk_rails_69ec920393; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fees
    ADD CONSTRAINT fk_rails_69ec920393 FOREIGN KEY (product_filter_id) REFERENCES public.product_filters(id);


--
-- Name: products fk_rails_6a4ad694b3; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT fk_rails_6a4ad694b3 FOREIGN KEY (charge_id) REFERENCES public.charges(id);


--
-- Name: usage_attribution_values fk_rails_6b11e175f4; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_attribution_values
    ADD CONSTRAINT fk_rails_6b11e175f4 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: dunning_campaigns fk_rails_6c720a8ccd; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dunning_campaigns
    ADD CONSTRAINT fk_rails_6c720a8ccd FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: rate_overrides fk_rails_6c8a54dfe1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rate_overrides
    ADD CONSTRAINT fk_rails_6c8a54dfe1 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: adjusted_fees fk_rails_6d465e6b10; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.adjusted_fees
    ADD CONSTRAINT fk_rails_6d465e6b10 FOREIGN KEY (group_id) REFERENCES public.groups(id);


--
-- Name: invoices_taxes fk_rails_6e148ccbb1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices_taxes
    ADD CONSTRAINT fk_rails_6e148ccbb1 FOREIGN KEY (tax_id) REFERENCES public.taxes(id) ON DELETE SET NULL;


--
-- Name: pending_vies_checks fk_rails_6e238f3bfc; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pending_vies_checks
    ADD CONSTRAINT fk_rails_6e238f3bfc FOREIGN KEY (billing_entity_id) REFERENCES public.billing_entities(id);


--
-- Name: subscriptions_invoice_custom_sections fk_rails_6eb8abe6cb; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions_invoice_custom_sections
    ADD CONSTRAINT fk_rails_6eb8abe6cb FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: quote_versions fk_rails_7082696669; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quote_versions
    ADD CONSTRAINT fk_rails_7082696669 FOREIGN KEY (billing_entity_id) REFERENCES public.billing_entities(id);


--
-- Name: usage_monitoring_alert_thresholds fk_rails_710f37148d; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_monitoring_alert_thresholds
    ADD CONSTRAINT fk_rails_710f37148d FOREIGN KEY (usage_monitoring_alert_id) REFERENCES public.usage_monitoring_alerts(id);


--
-- Name: invoice_connections fk_rails_7286421039; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_connections
    ADD CONSTRAINT fk_rails_7286421039 FOREIGN KEY (payment_provider_customer_id) REFERENCES public.payment_provider_customers(id);


--
-- Name: data_exports fk_rails_73d83e23b6; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.data_exports
    ADD CONSTRAINT fk_rails_73d83e23b6 FOREIGN KEY (membership_id) REFERENCES public.memberships(id);


--
-- Name: contracts fk_rails_7418f461c3; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contracts
    ADD CONSTRAINT fk_rails_7418f461c3 FOREIGN KEY (catalog_plan_id) REFERENCES public.catalog_plans(id);


--
-- Name: fees_taxes fk_rails_745b4ca7dd; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fees_taxes
    ADD CONSTRAINT fk_rails_745b4ca7dd FOREIGN KEY (fee_id) REFERENCES public.fees(id);


--
-- Name: fixed_charge_events fk_rails_752665cc51; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fixed_charge_events
    ADD CONSTRAINT fk_rails_752665cc51 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: refunds fk_rails_75577c354e; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.refunds
    ADD CONSTRAINT fk_rails_75577c354e FOREIGN KEY (payment_provider_customer_id) REFERENCES public.payment_provider_customers(id);


--
-- Name: integrations fk_rails_755d734f25; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integrations
    ADD CONSTRAINT fk_rails_755d734f25 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: fees fk_rails_7616c2aca1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fees
    ADD CONSTRAINT fk_rails_7616c2aca1 FOREIGN KEY (contract_rate_card_id) REFERENCES public.contract_rate_cards(id);


--
-- Name: commitments fk_rails_76ceb88c74; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.commitments
    ADD CONSTRAINT fk_rails_76ceb88c74 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: quote_owners fk_rails_7734750af9; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quote_owners
    ADD CONSTRAINT fk_rails_7734750af9 FOREIGN KEY (quote_id) REFERENCES public.quotes(id);


--
-- Name: fees fk_rails_775eb0ecd8; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fees
    ADD CONSTRAINT fk_rails_775eb0ecd8 FOREIGN KEY (original_fee_id) REFERENCES public.fees(id);


--
-- Name: refunds fk_rails_778360c382; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.refunds
    ADD CONSTRAINT fk_rails_778360c382 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: credit_notes_taxes fk_rails_77f2d4440d; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.credit_notes_taxes
    ADD CONSTRAINT fk_rails_77f2d4440d FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: groups fk_rails_7886e1bc34; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.groups
    ADD CONSTRAINT fk_rails_7886e1bc34 FOREIGN KEY (parent_group_id) REFERENCES public.groups(id);


--
-- Name: wallet_transactions fk_rails_78f6642ddf; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallet_transactions
    ADD CONSTRAINT fk_rails_78f6642ddf FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: applied_add_ons fk_rails_7995206484; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.applied_add_ons
    ADD CONSTRAINT fk_rails_7995206484 FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: billable_metric_filters fk_rails_7a0704ce72; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billable_metric_filters
    ADD CONSTRAINT fk_rails_7a0704ce72 FOREIGN KEY (billable_metric_id) REFERENCES public.billable_metrics(id);


--
-- Name: api_keys fk_rails_7aab96f30e; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_keys
    ADD CONSTRAINT fk_rails_7aab96f30e FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: adjusted_fees fk_rails_7b324610ad; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.adjusted_fees
    ADD CONSTRAINT fk_rails_7b324610ad FOREIGN KEY (charge_id) REFERENCES public.charges(id);


--
-- Name: invoice_custom_sections fk_rails_7c0e340dbd; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_custom_sections
    ADD CONSTRAINT fk_rails_7c0e340dbd FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: subscriptions_invoice_custom_sections fk_rails_7c63dd13f0; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions_invoice_custom_sections
    ADD CONSTRAINT fk_rails_7c63dd13f0 FOREIGN KEY (subscription_id) REFERENCES public.subscriptions(id);


--
-- Name: wallet_targets fk_rails_7d0e61668f; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallet_targets
    ADD CONSTRAINT fk_rails_7d0e61668f FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: charge_filter_values fk_rails_7da558cadc; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.charge_filter_values
    ADD CONSTRAINT fk_rails_7da558cadc FOREIGN KEY (charge_filter_id) REFERENCES public.charge_filters(id);


--
-- Name: billable_metrics fk_rails_7e8a2f26e5; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billable_metrics
    ADD CONSTRAINT fk_rails_7e8a2f26e5 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: charges fk_rails_7eb0484711; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.charges
    ADD CONSTRAINT fk_rails_7eb0484711 FOREIGN KEY (plan_id) REFERENCES public.plans(id);


--
-- Name: entitlement_features fk_rails_81d8b323cf; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entitlement_features
    ADD CONSTRAINT fk_rails_81d8b323cf FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: add_ons fk_rails_81e3b6abba; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.add_ons
    ADD CONSTRAINT fk_rails_81e3b6abba FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: wallet_targets fk_rails_81eedc32c0; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallet_targets
    ADD CONSTRAINT fk_rails_81eedc32c0 FOREIGN KEY (wallet_id) REFERENCES public.wallets(id);


--
-- Name: contract_rate_cards fk_rails_84643e7caf; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contract_rate_cards
    ADD CONSTRAINT fk_rails_84643e7caf FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: payment_methods fk_rails_84a67e8b40; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_methods
    ADD CONSTRAINT fk_rails_84a67e8b40 FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: payments fk_rails_84f4587409; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT fk_rails_84f4587409 FOREIGN KEY (payment_provider_id) REFERENCES public.payment_providers(id);


--
-- Name: wallet_transaction_consumptions fk_rails_85b9e72931; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallet_transaction_consumptions
    ADD CONSTRAINT fk_rails_85b9e72931 FOREIGN KEY (outbound_wallet_transaction_id) REFERENCES public.wallet_transactions(id);


--
-- Name: payment_provider_customers fk_rails_86676be631; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_provider_customers
    ADD CONSTRAINT fk_rails_86676be631 FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: billing_segments fk_rails_86cc43f205; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_segments
    ADD CONSTRAINT fk_rails_86cc43f205 FOREIGN KEY (rate_override_id) REFERENCES public.rate_overrides(id);


--
-- Name: wallets_invoice_custom_sections fk_rails_87bc3bd4cb; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallets_invoice_custom_sections
    ADD CONSTRAINT fk_rails_87bc3bd4cb FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: invoice_subscriptions fk_rails_88349fc20a; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_subscriptions
    ADD CONSTRAINT fk_rails_88349fc20a FOREIGN KEY (invoice_id) REFERENCES public.invoices(id);


--
-- Name: adjusted_fees fk_rails_885dc100ef; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.adjusted_fees
    ADD CONSTRAINT fk_rails_885dc100ef FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: entitlement_entitlement_values fk_rails_8887954ec7; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entitlement_entitlement_values
    ADD CONSTRAINT fk_rails_8887954ec7 FOREIGN KEY (entitlement_privilege_id) REFERENCES public.entitlement_privileges(id);


--
-- Name: add_ons_taxes fk_rails_89e1020aca; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.add_ons_taxes
    ADD CONSTRAINT fk_rails_89e1020aca FOREIGN KEY (tax_id) REFERENCES public.taxes(id);


--
-- Name: invoice_metadata fk_rails_8bb5b094c4; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_metadata
    ADD CONSTRAINT fk_rails_8bb5b094c4 FOREIGN KEY (invoice_id) REFERENCES public.invoices(id);


--
-- Name: fixed_charges_taxes fk_rails_8c09ee2428; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fixed_charges_taxes
    ADD CONSTRAINT fk_rails_8c09ee2428 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: usage_monitoring_alerts fk_rails_8c18828b53; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_monitoring_alerts
    ADD CONSTRAINT fk_rails_8c18828b53 FOREIGN KEY (billable_metric_id) REFERENCES public.billable_metrics(id);


--
-- Name: fees fk_rails_8da4c18005; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fees
    ADD CONSTRAINT fk_rails_8da4c18005 FOREIGN KEY (contract_id) REFERENCES public.contracts(id);


--
-- Name: usage_thresholds fk_rails_8df9bf2b6c; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_thresholds
    ADD CONSTRAINT fk_rails_8df9bf2b6c FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: applied_pricing_units fk_rails_8e0c3d0c5b; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.applied_pricing_units
    ADD CONSTRAINT fk_rails_8e0c3d0c5b FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: coupon_targets fk_rails_8eeaaf6494; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coupon_targets
    ADD CONSTRAINT fk_rails_8eeaaf6494 FOREIGN KEY (catalog_plan_id) REFERENCES public.catalog_plans(id);


--
-- Name: commitments_taxes fk_rails_8fa6f0d920; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.commitments_taxes
    ADD CONSTRAINT fk_rails_8fa6f0d920 FOREIGN KEY (commitment_id) REFERENCES public.commitments(id);


--
-- Name: fixed_charge_events fk_rails_90302b3ca3; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fixed_charge_events
    ADD CONSTRAINT fk_rails_90302b3ca3 FOREIGN KEY (subscription_id) REFERENCES public.subscriptions(id);


--
-- Name: data_export_parts fk_rails_909197908c; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.data_export_parts
    ADD CONSTRAINT fk_rails_909197908c FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: invoice_subscriptions fk_rails_90d93bd016; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_subscriptions
    ADD CONSTRAINT fk_rails_90d93bd016 FOREIGN KEY (subscription_id) REFERENCES public.subscriptions(id);


--
-- Name: adjusted_fees fk_rails_91802dc891; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.adjusted_fees
    ADD CONSTRAINT fk_rails_91802dc891 FOREIGN KEY (fixed_charge_id) REFERENCES public.fixed_charges(id);


--
-- Name: data_export_parts fk_rails_9298b8fdad; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.data_export_parts
    ADD CONSTRAINT fk_rails_9298b8fdad FOREIGN KEY (data_export_id) REFERENCES public.data_exports(id);


--
-- Name: usage_attribution_values fk_rails_92e94c6810; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_attribution_values
    ADD CONSTRAINT fk_rails_92e94c6810 FOREIGN KEY (usage_attribution_type_id) REFERENCES public.usage_attribution_types(id);


--
-- Name: rate_cards_taxes fk_rails_9457f0d2da; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rate_cards_taxes
    ADD CONSTRAINT fk_rails_9457f0d2da FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: customers fk_rails_94cc21031f; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT fk_rails_94cc21031f FOREIGN KEY (applied_dunning_campaign_id) REFERENCES public.dunning_campaigns(id);


--
-- Name: entitlement_subscription_feature_removals fk_rails_95df3194c5; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entitlement_subscription_feature_removals
    ADD CONSTRAINT fk_rails_95df3194c5 FOREIGN KEY (entitlement_privilege_id) REFERENCES public.entitlement_privileges(id);


--
-- Name: pending_vies_checks fk_rails_96fc54cd9a; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pending_vies_checks
    ADD CONSTRAINT fk_rails_96fc54cd9a FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: fixed_charge_events fk_rails_9881e28151; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fixed_charge_events
    ADD CONSTRAINT fk_rails_9881e28151 FOREIGN KEY (fixed_charge_id) REFERENCES public.fixed_charges(id);


--
-- Name: adjusted_fees fk_rails_98980b326b; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.adjusted_fees
    ADD CONSTRAINT fk_rails_98980b326b FOREIGN KEY (invoice_id) REFERENCES public.invoices(id);


--
-- Name: memberships fk_rails_99326fb65d; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.memberships
    ADD CONSTRAINT fk_rails_99326fb65d FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: active_storage_variant_records fk_rails_993965df05; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.active_storage_variant_records
    ADD CONSTRAINT fk_rails_993965df05 FOREIGN KEY (blob_id) REFERENCES public.active_storage_blobs(id);


--
-- Name: applied_usage_thresholds fk_rails_9c08b43701; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.applied_usage_thresholds
    ADD CONSTRAINT fk_rails_9c08b43701 FOREIGN KEY (usage_threshold_id) REFERENCES public.usage_thresholds(id);


--
-- Name: plans_taxes fk_rails_9c704027e2; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plans_taxes
    ADD CONSTRAINT fk_rails_9c704027e2 FOREIGN KEY (tax_id) REFERENCES public.taxes(id);


--
-- Name: applied_add_ons fk_rails_9c8e276cc0; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.applied_add_ons
    ADD CONSTRAINT fk_rails_9c8e276cc0 FOREIGN KEY (add_on_id) REFERENCES public.add_ons(id);


--
-- Name: usage_monitoring_alerts fk_rails_9d8812945e; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_monitoring_alerts
    ADD CONSTRAINT fk_rails_9d8812945e FOREIGN KEY (wallet_id) REFERENCES public.wallets(id);


--
-- Name: wallet_transactions_invoice_custom_sections fk_rails_9e3f99b7a2; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallet_transactions_invoice_custom_sections
    ADD CONSTRAINT fk_rails_9e3f99b7a2 FOREIGN KEY (invoice_custom_section_id) REFERENCES public.invoice_custom_sections(id);


--
-- Name: products fk_rails_9e90c6f4aa; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT fk_rails_9e90c6f4aa FOREIGN KEY (billable_metric_id) REFERENCES public.billable_metrics(id);


--
-- Name: wallet_transactions fk_rails_9ea6759859; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallet_transactions
    ADD CONSTRAINT fk_rails_9ea6759859 FOREIGN KEY (credit_note_id) REFERENCES public.credit_notes(id);


--
-- Name: credit_note_items fk_rails_9f22076477; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.credit_note_items
    ADD CONSTRAINT fk_rails_9f22076477 FOREIGN KEY (credit_note_id) REFERENCES public.credit_notes(id);


--
-- Name: fees fk_rails_9f724c2094; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fees
    ADD CONSTRAINT fk_rails_9f724c2094 FOREIGN KEY (rate_override_id) REFERENCES public.rate_overrides(id);


--
-- Name: contracts fk_rails_a00d802491; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contracts
    ADD CONSTRAINT fk_rails_a00d802491 FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: rate_cards fk_rails_a026c79cab; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rate_cards
    ADD CONSTRAINT fk_rails_a026c79cab FOREIGN KEY (product_filter_id) REFERENCES public.product_filters(id);


--
-- Name: quotes fk_rails_a1ab65f1f7; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quotes
    ADD CONSTRAINT fk_rails_a1ab65f1f7 FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: group_properties fk_rails_a2d2cb3819; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.group_properties
    ADD CONSTRAINT fk_rails_a2d2cb3819 FOREIGN KEY (group_id) REFERENCES public.groups(id) ON DELETE CASCADE;


--
-- Name: invoice_connections fk_rails_a3fff9bd72; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_connections
    ADD CONSTRAINT fk_rails_a3fff9bd72 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: contracts fk_rails_a48f7202cc; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contracts
    ADD CONSTRAINT fk_rails_a48f7202cc FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: billing_object_connections fk_rails_a5a0718a08; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_object_connections
    ADD CONSTRAINT fk_rails_a5a0718a08 FOREIGN KEY (payment_provider_customer_id) REFERENCES public.payment_provider_customers(id);


--
-- Name: rate_phases fk_rails_a5cd897b6f; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rate_phases
    ADD CONSTRAINT fk_rails_a5cd897b6f FOREIGN KEY (plan_rate_card_id) REFERENCES public.plan_rate_cards(id);


--
-- Name: plans_taxes fk_rails_a6d07eec6e; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plans_taxes
    ADD CONSTRAINT fk_rails_a6d07eec6e FOREIGN KEY (catalog_plan_id) REFERENCES public.catalog_plans(id);


--
-- Name: charges fk_rails_a710519346; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.charges
    ADD CONSTRAINT fk_rails_a710519346 FOREIGN KEY (billable_metric_id) REFERENCES public.billable_metrics(id);


--
-- Name: recurring_transaction_rules_invoice_custom_sections fk_rails_a7f20c73bb; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recurring_transaction_rules_invoice_custom_sections
    ADD CONSTRAINT fk_rails_a7f20c73bb FOREIGN KEY (invoice_custom_section_id) REFERENCES public.invoice_custom_sections(id);


--
-- Name: rate_phases fk_rails_a9ba49506f; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rate_phases
    ADD CONSTRAINT fk_rails_a9ba49506f FOREIGN KEY (rate_override_id) REFERENCES public.rate_overrides(id);


--
-- Name: integration_items fk_rails_a9dc2ea536; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integration_items
    ADD CONSTRAINT fk_rails_a9dc2ea536 FOREIGN KEY (integration_id) REFERENCES public.integrations(id);


--
-- Name: fixed_charges fk_rails_aa04ceacf6; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fixed_charges
    ADD CONSTRAINT fk_rails_aa04ceacf6 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: entitlement_entitlement_values fk_rails_aa34dd5db6; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entitlement_entitlement_values
    ADD CONSTRAINT fk_rails_aa34dd5db6 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: commitments_taxes fk_rails_aaa12f7d3e; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.commitments_taxes
    ADD CONSTRAINT fk_rails_aaa12f7d3e FOREIGN KEY (tax_id) REFERENCES public.taxes(id);


--
-- Name: usage_monitoring_subscription_activities fk_rails_ab16de0b32; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_monitoring_subscription_activities
    ADD CONSTRAINT fk_rails_ab16de0b32 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: charges_taxes fk_rails_ac146c9541; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.charges_taxes
    ADD CONSTRAINT fk_rails_ac146c9541 FOREIGN KEY (charge_id) REFERENCES public.charges(id);


--
-- Name: pricing_unit_usages fk_rails_aea6422e6a; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pricing_unit_usages
    ADD CONSTRAINT fk_rails_aea6422e6a FOREIGN KEY (fee_id) REFERENCES public.fees(id);


--
-- Name: billing_object_connections fk_rails_aed4cbd20b; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_object_connections
    ADD CONSTRAINT fk_rails_aed4cbd20b FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: daily_usages fk_rails_b07fc711f7; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.daily_usages
    ADD CONSTRAINT fk_rails_b07fc711f7 FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: billing_entities_invoice_custom_sections fk_rails_b283a89721; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_entities_invoice_custom_sections
    ADD CONSTRAINT fk_rails_b283a89721 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: entitlement_subscription_feature_removals fk_rails_b3864df641; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entitlement_subscription_feature_removals
    ADD CONSTRAINT fk_rails_b3864df641 FOREIGN KEY (entitlement_feature_id) REFERENCES public.entitlement_features(id);


--
-- Name: billing_segments fk_rails_b3bd992995; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_segments
    ADD CONSTRAINT fk_rails_b3bd992995 FOREIGN KEY (contract_id) REFERENCES public.contracts(id);


--
-- Name: fees fk_rails_b50dc82c1e; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fees
    ADD CONSTRAINT fk_rails_b50dc82c1e FOREIGN KEY (group_id) REFERENCES public.groups(id);


--
-- Name: entitlement_entitlements fk_rails_b61aa73940; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entitlement_entitlements
    ADD CONSTRAINT fk_rails_b61aa73940 FOREIGN KEY (entitlement_feature_id) REFERENCES public.entitlement_features(id);


--
-- Name: orders fk_rails_b687c6e23a; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT fk_rails_b687c6e23a FOREIGN KEY (order_form_id) REFERENCES public.order_forms(id);


--
-- Name: subscription_activation_rules fk_rails_b749d2045d; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_activation_rules
    ADD CONSTRAINT fk_rails_b749d2045d FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: billing_segments fk_rails_b868d0440a; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_segments
    ADD CONSTRAINT fk_rails_b868d0440a FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: presentation_breakdowns fk_rails_b8f3cabc8e; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.presentation_breakdowns
    ADD CONSTRAINT fk_rails_b8f3cabc8e FOREIGN KEY (fee_id) REFERENCES public.fees(id);


--
-- Name: wallet_transactions_invoice_custom_sections fk_rails_b974dac270; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallet_transactions_invoice_custom_sections
    ADD CONSTRAINT fk_rails_b974dac270 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: lifetime_usages fk_rails_ba128983c2; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lifetime_usages
    ADD CONSTRAINT fk_rails_ba128983c2 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: applied_coupons fk_rails_bacb46d2a3; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.applied_coupons
    ADD CONSTRAINT fk_rails_bacb46d2a3 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: plans_taxes fk_rails_bacde7a063; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plans_taxes
    ADD CONSTRAINT fk_rails_bacde7a063 FOREIGN KEY (plan_id) REFERENCES public.plans(id);


--
-- Name: rate_phases fk_rails_bc33c71114; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rate_phases
    ADD CONSTRAINT fk_rails_bc33c71114 FOREIGN KEY (contract_rate_card_id) REFERENCES public.contract_rate_cards(id);


--
-- Name: wallet_transactions fk_rails_bcb5aecd6c; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallet_transactions
    ADD CONSTRAINT fk_rails_bcb5aecd6c FOREIGN KEY (billing_entity_id) REFERENCES public.billing_entities(id);


--
-- Name: usage_monitoring_subscription_activities fk_rails_bda048a8d9; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_monitoring_subscription_activities
    ADD CONSTRAINT fk_rails_bda048a8d9 FOREIGN KEY (subscription_id) REFERENCES public.subscriptions(id);


--
-- Name: dunning_campaign_thresholds fk_rails_bf1f386f75; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dunning_campaign_thresholds
    ADD CONSTRAINT fk_rails_bf1f386f75 FOREIGN KEY (dunning_campaign_id) REFERENCES public.dunning_campaigns(id);


--
-- Name: charge_filter_values fk_rails_bf661ef73d; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.charge_filter_values
    ADD CONSTRAINT fk_rails_bf661ef73d FOREIGN KEY (billable_metric_filter_id) REFERENCES public.billable_metric_filters(id);


--
-- Name: customers fk_rails_bff25bb1bb; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT fk_rails_bff25bb1bb FOREIGN KEY (billing_entity_id) REFERENCES public.billing_entities(id);


--
-- Name: wallet_transactions fk_rails_c29bf4ff0f; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallet_transactions
    ADD CONSTRAINT fk_rails_c29bf4ff0f FOREIGN KEY (payment_method_id) REFERENCES public.payment_methods(id);


--
-- Name: active_storage_attachments fk_rails_c3b3935057; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.active_storage_attachments
    ADD CONSTRAINT fk_rails_c3b3935057 FOREIGN KEY (blob_id) REFERENCES public.active_storage_blobs(id);


--
-- Name: cs_admin_audit_logs fk_rails_c47aa068a0; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cs_admin_audit_logs
    ADD CONSTRAINT fk_rails_c47aa068a0 FOREIGN KEY (actor_user_id) REFERENCES public.users(id);


--
-- Name: pricing_unit_usages fk_rails_c545103d57; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pricing_unit_usages
    ADD CONSTRAINT fk_rails_c545103d57 FOREIGN KEY (pricing_unit_id) REFERENCES public.pricing_units(id);


--
-- Name: payment_methods fk_rails_c60c12efbd; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_methods
    ADD CONSTRAINT fk_rails_c60c12efbd FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: customers_invoice_custom_sections fk_rails_c64033bcb0; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers_invoice_custom_sections
    ADD CONSTRAINT fk_rails_c64033bcb0 FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: invites fk_rails_c71f4b2026; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invites
    ADD CONSTRAINT fk_rails_c71f4b2026 FOREIGN KEY (membership_id) REFERENCES public.memberships(id);


--
-- Name: subscriptions_invoice_custom_sections fk_rails_c82f03a405; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions_invoice_custom_sections
    ADD CONSTRAINT fk_rails_c82f03a405 FOREIGN KEY (invoice_custom_section_id) REFERENCES public.invoice_custom_sections(id);


--
-- Name: payment_methods fk_rails_c8606f586b; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_methods
    ADD CONSTRAINT fk_rails_c8606f586b FOREIGN KEY (payment_provider_customer_id) REFERENCES public.payment_provider_customers(id);


--
-- Name: entitlement_subscription_feature_removals fk_rails_c9183c59d9; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entitlement_subscription_feature_removals
    ADD CONSTRAINT fk_rails_c9183c59d9 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: contracts fk_rails_c92e315584; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contracts
    ADD CONSTRAINT fk_rails_c92e315584 FOREIGN KEY (billing_entity_id) REFERENCES public.billing_entities(id);


--
-- Name: usage_thresholds fk_rails_caeb5a3949; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_thresholds
    ADD CONSTRAINT fk_rails_caeb5a3949 FOREIGN KEY (plan_id) REFERENCES public.plans(id);


--
-- Name: plans fk_rails_cbf700aeb8; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plans
    ADD CONSTRAINT fk_rails_cbf700aeb8 FOREIGN KEY (parent_id) REFERENCES public.plans(id);


--
-- Name: integration_mappings fk_rails_cc318ad1ff; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integration_mappings
    ADD CONSTRAINT fk_rails_cc318ad1ff FOREIGN KEY (integration_id) REFERENCES public.integrations(id);


--
-- Name: pricing_units fk_rails_cd99351ee3; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pricing_units
    ADD CONSTRAINT fk_rails_cd99351ee3 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: subscription_fixed_charge_units_overrides fk_rails_cdaf36dc89; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_fixed_charge_units_overrides
    ADD CONSTRAINT fk_rails_cdaf36dc89 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: integration_customers fk_rails_ce2c63d69f; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integration_customers
    ADD CONSTRAINT fk_rails_ce2c63d69f FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: wallet_transactions fk_rails_d07bc24ce3; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallet_transactions
    ADD CONSTRAINT fk_rails_d07bc24ce3 FOREIGN KEY (wallet_id) REFERENCES public.wallets(id);


--
-- Name: item_metadata fk_rails_d0b1714507; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.item_metadata
    ADD CONSTRAINT fk_rails_d0b1714507 FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: quote_versions fk_rails_d2d917b73a; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quote_versions
    ADD CONSTRAINT fk_rails_d2d917b73a FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: payments fk_rails_d384ec1ebf; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT fk_rails_d384ec1ebf FOREIGN KEY (payment_method_id) REFERENCES public.payment_methods(id);


--
-- Name: wallet_transaction_consumptions fk_rails_d4abfdb375; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallet_transaction_consumptions
    ADD CONSTRAINT fk_rails_d4abfdb375 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: idempotency_records fk_rails_d4f02c82b2; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.idempotency_records
    ADD CONSTRAINT fk_rails_d4f02c82b2 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: entitlement_entitlements fk_rails_d53f825a88; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entitlement_entitlements
    ADD CONSTRAINT fk_rails_d53f825a88 FOREIGN KEY (plan_id) REFERENCES public.plans(id);


--
-- Name: rate_card_rates fk_rails_d53feb5721; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rate_card_rates
    ADD CONSTRAINT fk_rails_d53feb5721 FOREIGN KEY (rate_card_id) REFERENCES public.rate_cards(id);


--
-- Name: entitlement_privileges fk_rails_d648e28d9f; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entitlement_privileges
    ADD CONSTRAINT fk_rails_d648e28d9f FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: subscription_fixed_charge_units_overrides fk_rails_d72a9877be; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_fixed_charge_units_overrides
    ADD CONSTRAINT fk_rails_d72a9877be FOREIGN KEY (subscription_id) REFERENCES public.subscriptions(id);


--
-- Name: wallets fk_rails_d9342a8ca7; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallets
    ADD CONSTRAINT fk_rails_d9342a8ca7 FOREIGN KEY (payment_method_id) REFERENCES public.payment_methods(id);


--
-- Name: integration_resources fk_rails_d9448a540b; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integration_resources
    ADD CONSTRAINT fk_rails_d9448a540b FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: streaming_destinations fk_rails_d94733afa1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.streaming_destinations
    ADD CONSTRAINT fk_rails_d94733afa1 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: usage_monitoring_alerts fk_rails_d9ea200904; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_monitoring_alerts
    ADD CONSTRAINT fk_rails_d9ea200904 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: fees fk_rails_d9ffb8b4a1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fees
    ADD CONSTRAINT fk_rails_d9ffb8b4a1 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: contract_rate_cards fk_rails_da12dd4c55; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contract_rate_cards
    ADD CONSTRAINT fk_rails_da12dd4c55 FOREIGN KEY (contract_id) REFERENCES public.contracts(id);


--
-- Name: customers_invoice_custom_sections fk_rails_db9140d0fd; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers_invoice_custom_sections
    ADD CONSTRAINT fk_rails_db9140d0fd FOREIGN KEY (billing_entity_id) REFERENCES public.billing_entities(id);


--
-- Name: usage_attribution_values fk_rails_dc093a8283; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_attribution_values
    ADD CONSTRAINT fk_rails_dc093a8283 FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: invites fk_rails_dd342449a6; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invites
    ADD CONSTRAINT fk_rails_dd342449a6 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: rate_cards fk_rails_ddab7acbd0; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rate_cards
    ADD CONSTRAINT fk_rails_ddab7acbd0 FOREIGN KEY (product_id) REFERENCES public.products(id);


--
-- Name: coupon_targets fk_rails_de6b3c3138; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coupon_targets
    ADD CONSTRAINT fk_rails_de6b3c3138 FOREIGN KEY (plan_id) REFERENCES public.plans(id);


--
-- Name: quotes fk_rails_de7694c307; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quotes
    ADD CONSTRAINT fk_rails_de7694c307 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: credit_note_items fk_rails_dea748e529; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.credit_note_items
    ADD CONSTRAINT fk_rails_dea748e529 FOREIGN KEY (fee_id) REFERENCES public.fees(id);


--
-- Name: customer_metadata fk_rails_dfac602b2c; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_metadata
    ADD CONSTRAINT fk_rails_dfac602b2c FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: plan_rate_cards fk_rails_e05bca64df; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_rate_cards
    ADD CONSTRAINT fk_rails_e05bca64df FOREIGN KEY (catalog_plan_id) REFERENCES public.catalog_plans(id);


--
-- Name: product_categories fk_rails_e13d306a35; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_categories
    ADD CONSTRAINT fk_rails_e13d306a35 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: integration_collection_mappings fk_rails_e148d17c1f; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integration_collection_mappings
    ADD CONSTRAINT fk_rails_e148d17c1f FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: rate_cards_taxes fk_rails_e2dbfbb01e; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rate_cards_taxes
    ADD CONSTRAINT fk_rails_e2dbfbb01e FOREIGN KEY (rate_card_id) REFERENCES public.rate_cards(id);


--
-- Name: usage_monitoring_triggered_alerts fk_rails_e3cf54daac; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_monitoring_triggered_alerts
    ADD CONSTRAINT fk_rails_e3cf54daac FOREIGN KEY (usage_monitoring_alert_id) REFERENCES public.usage_monitoring_alerts(id);


--
-- Name: integration_mappings fk_rails_e4a58fbcac; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integration_mappings
    ADD CONSTRAINT fk_rails_e4a58fbcac FOREIGN KEY (billing_entity_id) REFERENCES public.billing_entities(id) ON DELETE CASCADE;


--
-- Name: user_devices fk_rails_e700a96826; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_devices
    ADD CONSTRAINT fk_rails_e700a96826 FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: charge_filters fk_rails_e711e8089e; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.charge_filters
    ADD CONSTRAINT fk_rails_e711e8089e FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: subscriptions fk_rails_e744efbe51; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT fk_rails_e744efbe51 FOREIGN KEY (payment_method_id) REFERENCES public.payment_methods(id);


--
-- Name: customers_taxes fk_rails_e86903e081; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers_taxes
    ADD CONSTRAINT fk_rails_e86903e081 FOREIGN KEY (tax_id) REFERENCES public.taxes(id);


--
-- Name: plans_taxes fk_rails_e88403f4b9; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plans_taxes
    ADD CONSTRAINT fk_rails_e88403f4b9 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: billing_segments fk_rails_e88ab4465f; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_segments
    ADD CONSTRAINT fk_rails_e88ab4465f FOREIGN KEY (pricing_unit_id) REFERENCES public.pricing_units(id);


--
-- Name: recurring_transaction_rules fk_rails_e8bac9c5bb; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recurring_transaction_rules
    ADD CONSTRAINT fk_rails_e8bac9c5bb FOREIGN KEY (wallet_id) REFERENCES public.wallets(id);


--
-- Name: rate_card_rates fk_rails_e910b02a08; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rate_card_rates
    ADD CONSTRAINT fk_rails_e910b02a08 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: fixed_charges fk_rails_e95f72749e; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fixed_charges
    ADD CONSTRAINT fk_rails_e95f72749e FOREIGN KEY (add_on_id) REFERENCES public.add_ons(id);


--
-- Name: integration_customers fk_rails_ea80151038; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integration_customers
    ADD CONSTRAINT fk_rails_ea80151038 FOREIGN KEY (integration_id) REFERENCES public.integrations(id);


--
-- Name: fees fk_rails_eaca9421be; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fees
    ADD CONSTRAINT fk_rails_eaca9421be FOREIGN KEY (invoice_id) REFERENCES public.invoices(id);


--
-- Name: payment_provider_customers fk_rails_ecb466254b; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_provider_customers
    ADD CONSTRAINT fk_rails_ecb466254b FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: invoices_payment_requests fk_rails_ed387e0992; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices_payment_requests
    ADD CONSTRAINT fk_rails_ed387e0992 FOREIGN KEY (payment_request_id) REFERENCES public.payment_requests(id);


--
-- Name: usage_monitoring_triggered_alerts fk_rails_ee2b6f04d9; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_monitoring_triggered_alerts
    ADD CONSTRAINT fk_rails_ee2b6f04d9 FOREIGN KEY (wallet_id) REFERENCES public.wallets(id);


--
-- Name: plan_rate_cards fk_rails_ee8d423cf4; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_rate_cards
    ADD CONSTRAINT fk_rails_ee8d423cf4 FOREIGN KEY (rate_card_id) REFERENCES public.rate_cards(id);


--
-- Name: recurring_transaction_rules_invoice_custom_sections fk_rails_eeb6a32be1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recurring_transaction_rules_invoice_custom_sections
    ADD CONSTRAINT fk_rails_eeb6a32be1 FOREIGN KEY (recurring_transaction_rule_id) REFERENCES public.recurring_transaction_rules(id);


--
-- Name: products fk_rails_efe167855e; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT fk_rails_efe167855e FOREIGN KEY (product_category_id) REFERENCES public.product_categories(id);


--
-- Name: usage_monitoring_alert_thresholds fk_rails_f18cd04d51; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_monitoring_alert_thresholds
    ADD CONSTRAINT fk_rails_f18cd04d51 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: payment_requests fk_rails_f228550fda; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_requests
    ADD CONSTRAINT fk_rails_f228550fda FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: wallet_transactions fk_rails_f32b205d44; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallet_transactions
    ADD CONSTRAINT fk_rails_f32b205d44 FOREIGN KEY (voided_invoice_id) REFERENCES public.invoices(id);


--
-- Name: fees fk_rails_f375d320ad; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fees
    ADD CONSTRAINT fk_rails_f375d320ad FOREIGN KEY (fixed_charge_id) REFERENCES public.fixed_charges(id);


--
-- Name: invoice_subscriptions fk_rails_f435d13904; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_subscriptions
    ADD CONSTRAINT fk_rails_f435d13904 FOREIGN KEY (regenerated_invoice_id) REFERENCES public.invoices(id);


--
-- Name: quantified_events fk_rails_f510acb495; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quantified_events
    ADD CONSTRAINT fk_rails_f510acb495 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: payment_receipts fk_rails_f53ff93138; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_receipts
    ADD CONSTRAINT fk_rails_f53ff93138 FOREIGN KEY (payment_id) REFERENCES public.payments(id);


--
-- Name: billing_entities fk_rails_f66617edcb; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_entities
    ADD CONSTRAINT fk_rails_f66617edcb FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: usage_attribution_types fk_rails_f7f094fe3a; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_attribution_types
    ADD CONSTRAINT fk_rails_f7f094fe3a FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: order_forms fk_rails_f94f882198; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.order_forms
    ADD CONSTRAINT fk_rails_f94f882198 FOREIGN KEY (quote_version_id) REFERENCES public.quote_versions(id);


--
-- Name: fees_taxes fk_rails_f98413d404; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fees_taxes
    ADD CONSTRAINT fk_rails_f98413d404 FOREIGN KEY (tax_id) REFERENCES public.taxes(id) ON DELETE SET NULL;


--
-- Name: product_filter_values fk_rails_f99eb07174; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_filter_values
    ADD CONSTRAINT fk_rails_f99eb07174 FOREIGN KEY (product_filter_id) REFERENCES public.product_filters(id);


--
-- Name: wallet_targets fk_rails_fbd2b9fccb; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallet_targets
    ADD CONSTRAINT fk_rails_fbd2b9fccb FOREIGN KEY (billable_metric_id) REFERENCES public.billable_metrics(id);


--
-- Name: adjusted_fees fk_rails_fd399a23d3; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.adjusted_fees
    ADD CONSTRAINT fk_rails_fd399a23d3 FOREIGN KEY (subscription_id) REFERENCES public.subscriptions(id);


--
-- Name: subscription_activation_rules fk_rails_fd60209637; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_activation_rules
    ADD CONSTRAINT fk_rails_fd60209637 FOREIGN KEY (subscription_id) REFERENCES public.subscriptions(id);


--
-- Name: dunning_campaign_thresholds fk_rails_fd84cdb7c6; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dunning_campaign_thresholds
    ADD CONSTRAINT fk_rails_fd84cdb7c6 FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: orders fk_rails_fe8af6535c; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT fk_rails_fe8af6535c FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: fixed_charges_taxes fk_rails_fea16bf2e7; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fixed_charges_taxes
    ADD CONSTRAINT fk_rails_fea16bf2e7 FOREIGN KEY (fixed_charge_id) REFERENCES public.fixed_charges(id);


--
-- Name: presentation_breakdowns fk_rails_ff548a9f4c; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.presentation_breakdowns
    ADD CONSTRAINT fk_rails_ff548a9f4c FOREIGN KEY (organization_id) REFERENCES public.organizations(id);


--
-- Name: wallet_transactions_invoice_custom_sections fk_rails_ff75b29299; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wallet_transactions_invoice_custom_sections
    ADD CONSTRAINT fk_rails_ff75b29299 FOREIGN KEY (wallet_transaction_id) REFERENCES public.wallet_transactions(id);


--
-- Name: membership_roles membership_role_membership_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.membership_roles
    ADD CONSTRAINT membership_role_membership_fk FOREIGN KEY (membership_id, organization_id) REFERENCES public.memberships(id, organization_id);


--
-- PostgreSQL database dump complete
--

SET search_path TO "$user", public;

