--
-- PostgreSQL database dump
--


-- Dumped from database version 15.0
-- Dumped by pg_dump version 18.6

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: active_storage_blobs; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: active_storage_attachments; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: active_storage_variant_records; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: organizations; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.organizations VALUES ('1a4a0d6e-0000-4000-8000-000000000061', 'Contract Package Org', '2025-05-02 11:00:00', '2025-05-02 11:00:00', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'raylene@bergnaum.example', NULL, NULL, NULL, NULL, NULL, 0, 'UTC', 'en', '{invoice.finalized,credit_note.created}', NULL, 0, 'USD', 0, 'CON-0061', false, '{}', false, true, false, '6c4b941b-a92b-4a13-878a-b498ffe61af8', '{email_password,google_oauth}', NULL, false, false, '{}', NULL, 'contract-package-org');


--
-- Data for Name: add_ons; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: taxes; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: add_ons_taxes; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: dunning_campaigns; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: billing_entities; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.billing_entities VALUES ('dede7896-6598-4016-8101-e4535f144d86', '1a4a0d6e-0000-4000-8000-000000000061', NULL, NULL, NULL, NULL, NULL, NULL, 'UTC', 'USD', 'en', 'FRA-4D86', 'per_customer', true, NULL, 0, 0, 'phil_herzog@weber-kris.example', '{invoice.finalized,credit_note.created}', false, NULL, NULL, NULL, 'Frami Inc', 'entity_acb488c6-77b3-4be5-8604-6585f657c30b', NULL, 0, NULL, NULL, '2025-05-02 11:00:00', '2025-05-02 11:00:00', NULL, false, 'next_period_start', 'align_with_finalization_date', NULL, NULL);


--
-- Data for Name: customers; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.customers VALUES ('f41a42c0-9b18-429a-b128-cd0293457b40', 'package-customer-1', 'Package Customer Co.', '1a4a0d6e-0000-4000-8000-000000000061', '2025-05-02 11:00:00', '2025-05-02 11:00:00', 'FR', '9 Rue des Forfaits', 'Apt. 969', 'IDF', '75009', 'package@example.invalid', 'Paris', 'http://olson-homenick.example/maragret', '488.397.8654', 'http://daniel.example/miquel', 'Wintheiser-Becker', '94-054-1691', NULL, NULL, 'CON-0061-001', 1, 'EUR', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'Madalene', 'Kuhn', NULL, NULL, false, 0, NULL, false, 'customer', 'dede7896-6598-4016-8101-e4535f144d86', 0, NULL, NULL, false, '{}', NULL);


--
-- Data for Name: applied_add_ons; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: billable_metrics; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.billable_metrics VALUES ('1a4a0d6e-0000-4000-8000-000000000062', '1a4a0d6e-0000-4000-8000-000000000061', 'Packaged API Calls', 'packaged_calls', 'seeded sum metric for the package charge', '{}', 1, '2025-05-02 11:00:00', '2025-05-02 11:00:00', 'calls', NULL, false, NULL, NULL, '', NULL, NULL);


--
-- Data for Name: catalog_plans; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: plans; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.plans VALUES ('47166fd4-320e-4e37-bafd-273174ee64d9', '1a4a0d6e-0000-4000-8000-000000000061', 'Package Plan', '2025-05-02 11:00:00', '2025-05-02 11:00:00', 'package-plan', 1, 'arrears plan with a package charge', 4900, 'EUR', NULL, false, NULL, NULL, NULL, false, 'Package Plan Display', false);


--
-- Data for Name: charges; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.charges VALUES ('1a4a0d6e-0000-4000-8000-000000000063', '1a4a0d6e-0000-4000-8000-000000000062', '2025-05-02 11:00:00', '2025-05-02 11:00:00', '47166fd4-320e-4e37-bafd-273174ee64d9', NULL, 2, '{"amount": "100", "free_units": 10, "package_size": 10}', NULL, false, 0, true, false, 'Packaged API calls', NULL, NULL, '1a4a0d6e-0000-4000-8000-000000000061', 'package-charge', false);


--
-- Data for Name: payment_providers; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: payment_provider_customers; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: payment_methods; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: contracts; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: product_categories; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: products; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: product_filters; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: rate_cards; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: contract_rate_cards; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: fixed_charges; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: groups; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: invoices; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: rate_card_rates; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: rate_overrides; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: subscriptions; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: fees; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: adjusted_fees; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: memberships; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: ai_conversations; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: api_keys; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.api_keys VALUES ('57f39a02-df1f-48e3-82e4-a88f1169861d', '1a4a0d6e-0000-4000-8000-000000000061', '1a4a0d6e-0000-4000-8000-00000000006e', '2025-05-02 11:00:00', '2025-05-02 11:00:00', NULL, NULL, 'Package Capture Key', '{"fee": ["read", "write"], "tax": ["read", "write"], "plan": ["read", "write"], "x402": ["read", "write"], "alert": ["read", "write"], "event": ["read", "write"], "order": ["read", "write"], "quote": ["read", "write"], "add_on": ["read", "write"], "coupon": ["read", "write"], "wallet": ["read", "write"], "api_log": ["read", "write"], "feature": ["read", "write"], "invoice": ["read", "write"], "payment": ["read", "write"], "product": ["read", "write"], "analytic": ["read", "write"], "contract": ["read", "write"], "customer": ["read", "write"], "rate_card": ["read", "write"], "order_form": ["read", "write"], "credit_note": ["read", "write"], "activity_log": ["read", "write"], "organization": ["read", "write"], "security_log": ["read", "write"], "subscription": ["read", "write"], "applied_coupon": ["read", "write"], "billing_entity": ["read", "write"], "customer_usage": ["read", "write"], "lifetime_usage": ["read", "write"], "payment_method": ["read", "write"], "plan_rate_card": ["read", "write"], "billable_metric": ["read", "write"], "payment_receipt": ["read", "write"], "payment_request": ["read", "write"], "x402_connection": ["read", "write"], "product_category": ["read", "write"], "webhook_endpoint": ["read", "write"], "contract_rate_card": ["read", "write"], "wallet_transaction": ["read", "write"], "invoice_custom_section": ["read", "write"], "usage_attribution_type": ["read", "write"], "webhook_jwt_public_key": ["read", "write"]}');


--
-- Data for Name: applied_coupons; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: applied_invoice_custom_sections; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: pricing_units; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: applied_pricing_units; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: usage_thresholds; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: applied_usage_thresholds; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: billable_metric_filters; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: invoice_custom_sections; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: billing_entities_invoice_custom_sections; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: billing_entities_taxes; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: integrations; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: integration_customers; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: billing_object_connections; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: billing_segments; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: cached_aggregations; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.cached_aggregations VALUES ('8f5b8df4-b470-464d-bd23-10a8556ada22', '1a4a0d6e-0000-4000-8000-000000000061', '2025-05-14 09:00:00', 'package-sub-1', '1a4a0d6e-0000-4000-8000-000000000063', NULL, 121.0, 121.0, NULL, '2025-05-02 11:00:00', '2025-05-02 11:00:00', '{}', NULL, NULL, 'db44fac8-f199-4948-99db-b47efa14edf4', '[]');


--
-- Data for Name: charge_filters; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: charge_filter_values; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: charges_taxes; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: commitments; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: commitments_taxes; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: coupons; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: coupon_targets; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: credit_notes; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: credit_note_items; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: credit_notes_taxes; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: credits; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: cs_admin_audit_logs; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: customer_metadata; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: customers_invoice_custom_sections; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: customers_taxes; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: daily_usages; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: data_exports; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: data_export_parts; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: dunning_campaign_thresholds; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: enriched_events_default; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: entitlement_features; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: entitlement_entitlements; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: entitlement_privileges; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: entitlement_entitlement_values; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: entitlement_subscription_feature_removals; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: error_details; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: events; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.events VALUES ('35f59731-331c-4638-b7a3-1174f5cbcdc7', '1a4a0d6e-0000-4000-8000-000000000061', NULL, 'tr-package-1', 'packaged_calls', '{"calls": 50}', '2025-05-10 09:00:00', '2025-05-02 11:00:00', '2025-05-02 11:00:00', '{}', NULL, NULL, 'package-customer-1', 'package-sub-1', NULL);
INSERT INTO public.events VALUES ('d0c0550a-5bc5-4b8f-bd39-d29953418552', '1a4a0d6e-0000-4000-8000-000000000061', NULL, 'tr-package-2', 'packaged_calls', '{"calls": 50}', '2025-05-11 09:00:00', '2025-05-02 11:00:00', '2025-05-02 11:00:00', '{}', NULL, NULL, 'package-customer-1', 'package-sub-1', NULL);
INSERT INTO public.events VALUES ('1f86f363-27e8-4f77-b10d-6df45c3ba55d', '1a4a0d6e-0000-4000-8000-000000000061', NULL, 'tr-package-3', 'packaged_calls', '{"calls": 21}', '2025-05-14 09:00:00', '2025-05-02 11:00:00', '2025-05-02 11:00:00', '{}', NULL, NULL, 'package-customer-1', 'package-sub-1', NULL);


--
-- Data for Name: fees_taxes; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: fixed_charge_events; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: fixed_charges_taxes; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: group_properties; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: idempotency_records; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: inbound_webhooks; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: integration_collection_mappings; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: integration_items; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: integration_mappings; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: integration_resources; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: invites; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: invoice_connections; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: invoice_metadata; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: payments; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: invoice_settlements; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: invoice_subscriptions; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: payment_requests; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: invoices_payment_requests; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: invoices_taxes; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: item_metadata; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: lifetime_usages; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: roles; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: membership_roles; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: quotes; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: quote_versions; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: order_forms; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: orders; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: password_resets; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: payment_intents; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: payment_receipts; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: pending_vies_checks; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: plan_rate_cards; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: plans_taxes; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: presentation_breakdowns; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: pricing_unit_usages; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: product_filter_values; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: quantified_events; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: quote_owners; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: rate_cards_taxes; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: rate_phases; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: record_deletions; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: wallets; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: recurring_transaction_rules; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: recurring_transaction_rules_invoice_custom_sections; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: refunds; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: streaming_destinations; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: subscription_activation_rules; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: subscription_fixed_charge_units_overrides; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: subscriptions_invoice_custom_sections; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: usage_attribution_types; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: usage_attribution_values; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: usage_monitoring_alerts; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: usage_monitoring_alert_thresholds; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: usage_monitoring_subscription_activities; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: usage_monitoring_triggered_alerts; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: user_devices; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: versions; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.versions VALUES (1, 'Organization', '1a4a0d6e-0000-4000-8000-000000000061', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000061"], "name": [null, "Contract Package Org"], "slug": [null, "contract-package-org"], "email": [null, "raylene@bergnaum.example"], "hmac_key": [null, "6c4b941b-a92b-4a13-878a-b498ffe61af8"], "created_at": [null, "2025-05-02T11:00:00.000Z"], "updated_at": [null, "2025-05-02T11:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "audit_logs_period": [30, null]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (2, 'BillingEntity', 'dede7896-6598-4016-8101-e4535f144d86', 'create', NULL, NULL, '{"id": [null, "dede7896-6598-4016-8101-e4535f144d86"], "code": [null, "entity_acb488c6-77b3-4be5-8604-6585f657c30b"], "name": [null, "Frami Inc"], "email": [null, "phil_herzog@weber-kris.example"], "created_at": [null, "2025-05-02T11:00:00.000Z"], "updated_at": [null, "2025-05-02T11:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000061"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (3, 'BillingEntity', 'dede7896-6598-4016-8101-e4535f144d86', 'update', NULL, '{"id": "dede7896-6598-4016-8101-e4535f144d86", "city": null, "code": "entity_acb488c6-77b3-4be5-8604-6585f657c30b", "logo": null, "name": "Frami Inc", "email": "phil_herzog@weber-kris.example", "phone": null, "state": null, "country": null, "zipcode": null, "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-05-02T11:00:00.000Z", "deleted_at": null, "einvoicing": false, "legal_name": null, "updated_at": "2025-05-02T11:00:00.000Z", "archived_at": null, "legal_number": null, "payment_term": null, "address_line1": null, "address_line2": null, "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "organization_id": "1a4a0d6e-0000-4000-8000-000000000061", "default_currency": "USD", "net_payment_term": 0, "eu_tax_management": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "document_number_prefix": null, "tax_identification_number": null, "applied_dunning_campaign_id": null, "finalize_zero_amount_invoice": true, "subscription_invoice_issuing_date_anchor": "next_period_start", "subscription_invoice_issuing_date_adjustment": "align_with_finalization_date"}', '{"document_number_prefix": [null, "FRA-4D86"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (4, 'Organization', '1a4a0d6e-0000-4000-8000-000000000061', 'update', NULL, '{"id": "1a4a0d6e-0000-4000-8000-000000000061", "city": null, "logo": null, "name": "Contract Package Org", "slug": "contract-package-org", "email": "raylene@bergnaum.example", "state": null, "api_key": null, "country": null, "zipcode": null, "hmac_key": "6c4b941b-a92b-4a13-878a-b498ffe61af8", "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-05-02T11:00:00.000Z", "legal_name": null, "updated_at": "2025-05-02T11:00:00.000Z", "max_wallets": null, "webhook_url": null, "legal_number": null, "address_line1": null, "address_line2": null, "feature_flags": [], "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "default_currency": "USD", "net_payment_term": 0, "audit_logs_period": null, "eu_tax_management": false, "custom_aggregation": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "premium_integrations": [], "authentication_methods": ["email_password", "google_oauth"], "document_number_prefix": null, "clickhouse_events_store": false, "tax_identification_number": null, "finalize_zero_amount_invoice": true, "clickhouse_deduplication_enabled": false}', '{"document_number_prefix": [null, "CON-0061"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (5, 'ApiKey', '57f39a02-df1f-48e3-82e4-a88f1169861d', 'create', NULL, NULL, '{"id": [null, "57f39a02-df1f-48e3-82e4-a88f1169861d"], "name": [null, "Package Capture Key"], "value": [null, "784dffbc-fa1c-4317-bb49-efac9d37e455"], "created_at": [null, "2025-05-02T11:00:00.000Z"], "updated_at": [null, "2025-05-02T11:00:00.000Z"], "permissions": [null, {"fee": ["read", "write"], "tax": ["read", "write"], "plan": ["read", "write"], "x402": ["read", "write"], "alert": ["read", "write"], "event": ["read", "write"], "order": ["read", "write"], "quote": ["read", "write"], "add_on": ["read", "write"], "coupon": ["read", "write"], "wallet": ["read", "write"], "api_log": ["read", "write"], "feature": ["read", "write"], "invoice": ["read", "write"], "payment": ["read", "write"], "product": ["read", "write"], "analytic": ["read", "write"], "contract": ["read", "write"], "customer": ["read", "write"], "rate_card": ["read", "write"], "order_form": ["read", "write"], "credit_note": ["read", "write"], "activity_log": ["read", "write"], "organization": ["read", "write"], "security_log": ["read", "write"], "subscription": ["read", "write"], "applied_coupon": ["read", "write"], "billing_entity": ["read", "write"], "customer_usage": ["read", "write"], "lifetime_usage": ["read", "write"], "payment_method": ["read", "write"], "plan_rate_card": ["read", "write"], "billable_metric": ["read", "write"], "payment_receipt": ["read", "write"], "payment_request": ["read", "write"], "x402_connection": ["read", "write"], "product_category": ["read", "write"], "webhook_endpoint": ["read", "write"], "contract_rate_card": ["read", "write"], "wallet_transaction": ["read", "write"], "invoice_custom_section": ["read", "write"], "usage_attribution_type": ["read", "write"], "webhook_jwt_public_key": ["read", "write"]}], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000061"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (6, 'Customer', 'f41a42c0-9b18-429a-b128-cd0293457b40', 'create', NULL, NULL, '{"id": [null, "f41a42c0-9b18-429a-b128-cd0293457b40"], "url": [null, "http://olson-homenick.example/maragret"], "city": [null, "Paris"], "name": [null, "Package Customer Co."], "slug": [null, "CON-0061-001"], "email": [null, "package@example.invalid"], "phone": [null, "488.397.8654"], "state": [null, "IDF"], "country": [null, "FR"], "zipcode": [null, "75009"], "currency": [null, "EUR"], "lastname": [null, "Kuhn"], "logo_url": [null, "http://daniel.example/miquel"], "firstname": [null, "Madalene"], "created_at": [null, "2025-05-02T11:00:00.000Z"], "legal_name": [null, "Wintheiser-Becker"], "updated_at": [null, "2025-05-02T11:00:00.000Z"], "external_id": [null, "package-customer-1"], "legal_number": [null, "94-054-1691"], "address_line1": [null, "9 Rue des Forfaits"], "address_line2": [null, "Apt. 969"], "sequential_id": [null, 1], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000061"], "billing_entity_id": [null, "dede7896-6598-4016-8101-e4535f144d86"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (7, 'BillableMetric', '1a4a0d6e-0000-4000-8000-000000000062', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000062"], "code": [null, "packaged_calls"], "name": [null, "Packaged API Calls"], "created_at": [null, "2025-05-02T11:00:00.000Z"], "expression": [null, ""], "field_name": [null, "calls"], "updated_at": [null, "2025-05-02T11:00:00.000Z"], "description": [null, "seeded sum metric for the package charge"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000061"], "aggregation_type": [null, "sum_agg"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (8, 'Plan', '47166fd4-320e-4e37-bafd-273174ee64d9', 'create', NULL, NULL, '{"id": [null, "47166fd4-320e-4e37-bafd-273174ee64d9"], "code": [null, "package-plan"], "name": [null, "Package Plan"], "interval": [null, "monthly"], "created_at": [null, "2025-05-02T11:00:00.000Z"], "updated_at": [null, "2025-05-02T11:00:00.000Z"], "description": [null, "arrears plan with a package charge"], "amount_cents": [null, 4900], "amount_currency": [null, "EUR"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000061"], "invoice_display_name": [null, "Package Plan Display"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (9, 'Charge', '1a4a0d6e-0000-4000-8000-000000000063', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000063"], "code": [null, "package-charge"], "plan_id": [null, "47166fd4-320e-4e37-bafd-273174ee64d9"], "created_at": [null, "2025-05-02T11:00:00.000Z"], "properties": ["{}", {"amount": "100", "free_units": 10, "package_size": 10}], "updated_at": [null, "2025-05-02T11:00:00.000Z"], "charge_model": ["standard", "package"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000061"], "billable_metric_id": [null, "1a4a0d6e-0000-4000-8000-000000000062"], "invoice_display_name": [null, "Packaged API calls"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (10, 'Plan', '47166fd4-320e-4e37-bafd-273174ee64d9', 'update', NULL, '{"id": "47166fd4-320e-4e37-bafd-273174ee64d9", "code": "package-plan", "name": "Package Plan", "interval": "monthly", "parent_id": null, "created_at": "2025-05-02T11:00:00.000Z", "deleted_at": null, "updated_at": "2025-05-02T11:00:00.000Z", "description": "arrears plan with a package charge", "amount_cents": 4900, "trial_period": null, "pay_in_advance": false, "amount_currency": "EUR", "organization_id": "1a4a0d6e-0000-4000-8000-000000000061", "pending_deletion": false, "bill_charges_monthly": null, "invoice_display_name": "Package Plan Display", "bill_fixed_charges_monthly": false}', NULL, '2025-05-02 11:00:00', 'test');


--
-- Data for Name: wallet_targets; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: wallet_transactions; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: wallet_transaction_consumptions; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: wallet_transactions_invoice_custom_sections; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: wallets_invoice_custom_sections; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: webhook_endpoints; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: webhooks; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Name: quote_owners_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.quote_owners_id_seq', 1, false);


--
-- Name: usage_monitoring_subscription_activities_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.usage_monitoring_subscription_activities_id_seq', 1, false);


--
-- Name: versions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.versions_id_seq', 10, true);


--
-- PostgreSQL database dump complete
--


