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

INSERT INTO public.organizations VALUES ('1a4a0d6e-0000-4000-8000-000000000071', 'Contract Percentage Org', '2025-05-02 11:00:00', '2025-05-02 11:00:00', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'elbert.tremblay@ondricka-mraz.test', NULL, NULL, NULL, NULL, NULL, 0, 'UTC', 'en', '{invoice.finalized,credit_note.created}', NULL, 0, 'USD', 0, 'CON-0071', false, '{}', false, true, false, '71ed84a2-7125-4ae7-b80e-6c5fac69576d', '{email_password,google_oauth}', NULL, false, false, '{}', NULL, 'contract-percentage-org');


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

INSERT INTO public.billing_entities VALUES ('0c0223ac-d123-4e4d-a7f4-d2332e4f1832', '1a4a0d6e-0000-4000-8000-000000000071', NULL, NULL, NULL, NULL, NULL, NULL, 'UTC', 'USD', 'en', 'KIE-1832', 'per_customer', true, NULL, 0, 0, 'jefferson@douglas.test', '{invoice.finalized,credit_note.created}', false, NULL, NULL, NULL, 'Kiehn-Langosh', 'entity_c695d544-647b-46c4-83b3-b01cfa010982', NULL, 0, NULL, NULL, '2025-05-02 11:00:00', '2025-05-02 11:00:00', NULL, false, 'next_period_start', 'align_with_finalization_date', NULL, NULL);


--
-- Data for Name: customers; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.customers VALUES ('3b8e8d57-1a9b-42b9-9de2-f2b8a4e9df7d', 'percentage-customer-1', 'Percentage Customer Co.', '1a4a0d6e-0000-4000-8000-000000000071', '2025-05-02 11:00:00', '2025-05-02 11:00:00', 'FR', '11 Rue des Pourcentages', 'Suite 537', 'IDF', '75011', 'percentage@example.invalid', 'Paris', 'http://gerhold-leannon.test/crysta', '(539) 467 7734', 'http://stiedemann.test/mikki', 'O''Connell-Ernser', '85-864-9317', NULL, NULL, 'CON-0071-001', 1, 'EUR', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'Dawna', 'Feest', NULL, NULL, false, 0, NULL, false, 'customer', '0c0223ac-d123-4e4d-a7f4-d2332e4f1832', 0, NULL, NULL, false, '{}', NULL);


--
-- Data for Name: applied_add_ons; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: billable_metrics; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.billable_metrics VALUES ('1a4a0d6e-0000-4000-8000-000000000072', '1a4a0d6e-0000-4000-8000-000000000071', 'Charged API Calls', 'charged_calls', 'seeded sum metric for the percentage charge', '{}', 1, '2025-05-02 11:00:00', '2025-05-02 11:00:00', 'calls', NULL, false, NULL, NULL, '', NULL, NULL);


--
-- Data for Name: catalog_plans; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: plans; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.plans VALUES ('6acfe671-1f18-4afc-9d81-40ce3d4de31f', '1a4a0d6e-0000-4000-8000-000000000071', 'Percentage Plan', '2025-05-02 11:00:00', '2025-05-02 11:00:00', 'percentage-plan', 1, 'arrears plan with a percentage charge', 4900, 'EUR', NULL, false, NULL, NULL, NULL, false, 'Percentage Plan Display', false);


--
-- Data for Name: charges; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.charges VALUES ('1a4a0d6e-0000-4000-8000-000000000073', '1a4a0d6e-0000-4000-8000-000000000072', '2025-05-02 11:00:00', '2025-05-02 11:00:00', '6acfe671-1f18-4afc-9d81-40ce3d4de31f', NULL, 3, '{"rate": "1.3", "fixed_amount": "2.0", "free_units_per_events": 3, "per_transaction_max_amount": null, "per_transaction_min_amount": null, "free_units_per_total_aggregation": "250.0"}', NULL, false, 0, true, false, 'Charged API calls', NULL, NULL, '1a4a0d6e-0000-4000-8000-000000000071', 'percentage-charge', false);


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

INSERT INTO public.api_keys VALUES ('8f9d532f-4dd5-45ff-86c4-346bea4f6e60', '1a4a0d6e-0000-4000-8000-000000000071', '1a4a0d6e-0000-4000-8000-00000000007e', '2025-05-02 11:00:00', '2025-05-02 11:00:00', NULL, NULL, 'Percentage Capture Key', '{"fee": ["read", "write"], "tax": ["read", "write"], "plan": ["read", "write"], "x402": ["read", "write"], "alert": ["read", "write"], "event": ["read", "write"], "order": ["read", "write"], "quote": ["read", "write"], "add_on": ["read", "write"], "coupon": ["read", "write"], "wallet": ["read", "write"], "api_log": ["read", "write"], "feature": ["read", "write"], "invoice": ["read", "write"], "payment": ["read", "write"], "product": ["read", "write"], "analytic": ["read", "write"], "contract": ["read", "write"], "customer": ["read", "write"], "rate_card": ["read", "write"], "order_form": ["read", "write"], "credit_note": ["read", "write"], "activity_log": ["read", "write"], "organization": ["read", "write"], "security_log": ["read", "write"], "subscription": ["read", "write"], "applied_coupon": ["read", "write"], "billing_entity": ["read", "write"], "customer_usage": ["read", "write"], "lifetime_usage": ["read", "write"], "payment_method": ["read", "write"], "plan_rate_card": ["read", "write"], "billable_metric": ["read", "write"], "payment_receipt": ["read", "write"], "payment_request": ["read", "write"], "x402_connection": ["read", "write"], "product_category": ["read", "write"], "webhook_endpoint": ["read", "write"], "contract_rate_card": ["read", "write"], "wallet_transaction": ["read", "write"], "invoice_custom_section": ["read", "write"], "usage_attribution_type": ["read", "write"], "webhook_jwt_public_key": ["read", "write"]}');


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

INSERT INTO public.cached_aggregations VALUES ('41240130-ce78-4479-9d3f-3cf6695daa53', '1a4a0d6e-0000-4000-8000-000000000071', '2025-05-14 09:00:00', 'percentage-sub-1', '1a4a0d6e-0000-4000-8000-000000000073', NULL, 800.0, 800.0, NULL, '2025-05-02 11:00:00', '2025-05-02 11:00:00', '{}', NULL, NULL, '042dff94-b858-45b4-bc0d-b990ce61ad0c', '[]');


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

INSERT INTO public.events VALUES ('f7d7c7e3-f25a-4d0d-9548-015bada9c8b3', '1a4a0d6e-0000-4000-8000-000000000071', NULL, 'tr-percentage-1', 'charged_calls', '{"calls": 200}', '2025-05-10 09:00:00', '2025-05-02 11:00:00', '2025-05-02 11:00:00', '{}', NULL, NULL, 'percentage-customer-1', 'percentage-sub-1', NULL);
INSERT INTO public.events VALUES ('bb445d83-7a5f-4f9b-b484-ecf92e24b380', '1a4a0d6e-0000-4000-8000-000000000071', NULL, 'tr-percentage-2', 'charged_calls', '{"calls": 200}', '2025-05-11 09:00:00', '2025-05-02 11:00:00', '2025-05-02 11:00:00', '{}', NULL, NULL, 'percentage-customer-1', 'percentage-sub-1', NULL);
INSERT INTO public.events VALUES ('9c80b411-9412-4ea7-ae1b-b16cb12a62be', '1a4a0d6e-0000-4000-8000-000000000071', NULL, 'tr-percentage-3', 'charged_calls', '{"calls": 200}', '2025-05-12 09:00:00', '2025-05-02 11:00:00', '2025-05-02 11:00:00', '{}', NULL, NULL, 'percentage-customer-1', 'percentage-sub-1', NULL);
INSERT INTO public.events VALUES ('df401e46-2062-46f8-9972-d1628a3b2af8', '1a4a0d6e-0000-4000-8000-000000000071', NULL, 'tr-percentage-4', 'charged_calls', '{"calls": 200}', '2025-05-14 09:00:00', '2025-05-02 11:00:00', '2025-05-02 11:00:00', '{}', NULL, NULL, 'percentage-customer-1', 'percentage-sub-1', NULL);


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

INSERT INTO public.versions VALUES (1, 'Organization', '1a4a0d6e-0000-4000-8000-000000000071', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000071"], "name": [null, "Contract Percentage Org"], "slug": [null, "contract-percentage-org"], "email": [null, "elbert.tremblay@ondricka-mraz.test"], "hmac_key": [null, "71ed84a2-7125-4ae7-b80e-6c5fac69576d"], "created_at": [null, "2025-05-02T11:00:00.000Z"], "updated_at": [null, "2025-05-02T11:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "audit_logs_period": [30, null]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (2, 'BillingEntity', '0c0223ac-d123-4e4d-a7f4-d2332e4f1832', 'create', NULL, NULL, '{"id": [null, "0c0223ac-d123-4e4d-a7f4-d2332e4f1832"], "code": [null, "entity_c695d544-647b-46c4-83b3-b01cfa010982"], "name": [null, "Kiehn-Langosh"], "email": [null, "jefferson@douglas.test"], "created_at": [null, "2025-05-02T11:00:00.000Z"], "updated_at": [null, "2025-05-02T11:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000071"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (3, 'BillingEntity', '0c0223ac-d123-4e4d-a7f4-d2332e4f1832', 'update', NULL, '{"id": "0c0223ac-d123-4e4d-a7f4-d2332e4f1832", "city": null, "code": "entity_c695d544-647b-46c4-83b3-b01cfa010982", "logo": null, "name": "Kiehn-Langosh", "email": "jefferson@douglas.test", "phone": null, "state": null, "country": null, "zipcode": null, "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-05-02T11:00:00.000Z", "deleted_at": null, "einvoicing": false, "legal_name": null, "updated_at": "2025-05-02T11:00:00.000Z", "archived_at": null, "legal_number": null, "payment_term": null, "address_line1": null, "address_line2": null, "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "organization_id": "1a4a0d6e-0000-4000-8000-000000000071", "default_currency": "USD", "net_payment_term": 0, "eu_tax_management": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "document_number_prefix": null, "tax_identification_number": null, "applied_dunning_campaign_id": null, "finalize_zero_amount_invoice": true, "subscription_invoice_issuing_date_anchor": "next_period_start", "subscription_invoice_issuing_date_adjustment": "align_with_finalization_date"}', '{"document_number_prefix": [null, "KIE-1832"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (4, 'Organization', '1a4a0d6e-0000-4000-8000-000000000071', 'update', NULL, '{"id": "1a4a0d6e-0000-4000-8000-000000000071", "city": null, "logo": null, "name": "Contract Percentage Org", "slug": "contract-percentage-org", "email": "elbert.tremblay@ondricka-mraz.test", "state": null, "api_key": null, "country": null, "zipcode": null, "hmac_key": "71ed84a2-7125-4ae7-b80e-6c5fac69576d", "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-05-02T11:00:00.000Z", "legal_name": null, "updated_at": "2025-05-02T11:00:00.000Z", "max_wallets": null, "webhook_url": null, "legal_number": null, "address_line1": null, "address_line2": null, "feature_flags": [], "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "default_currency": "USD", "net_payment_term": 0, "audit_logs_period": null, "eu_tax_management": false, "custom_aggregation": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "premium_integrations": [], "authentication_methods": ["email_password", "google_oauth"], "document_number_prefix": null, "clickhouse_events_store": false, "tax_identification_number": null, "finalize_zero_amount_invoice": true, "clickhouse_deduplication_enabled": false}', '{"document_number_prefix": [null, "CON-0071"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (5, 'ApiKey', '8f9d532f-4dd5-45ff-86c4-346bea4f6e60', 'create', NULL, NULL, '{"id": [null, "8f9d532f-4dd5-45ff-86c4-346bea4f6e60"], "name": [null, "Percentage Capture Key"], "value": [null, "2d33f099-bd22-442c-ab05-ab89789c9df6"], "created_at": [null, "2025-05-02T11:00:00.000Z"], "updated_at": [null, "2025-05-02T11:00:00.000Z"], "permissions": [null, {"fee": ["read", "write"], "tax": ["read", "write"], "plan": ["read", "write"], "x402": ["read", "write"], "alert": ["read", "write"], "event": ["read", "write"], "order": ["read", "write"], "quote": ["read", "write"], "add_on": ["read", "write"], "coupon": ["read", "write"], "wallet": ["read", "write"], "api_log": ["read", "write"], "feature": ["read", "write"], "invoice": ["read", "write"], "payment": ["read", "write"], "product": ["read", "write"], "analytic": ["read", "write"], "contract": ["read", "write"], "customer": ["read", "write"], "rate_card": ["read", "write"], "order_form": ["read", "write"], "credit_note": ["read", "write"], "activity_log": ["read", "write"], "organization": ["read", "write"], "security_log": ["read", "write"], "subscription": ["read", "write"], "applied_coupon": ["read", "write"], "billing_entity": ["read", "write"], "customer_usage": ["read", "write"], "lifetime_usage": ["read", "write"], "payment_method": ["read", "write"], "plan_rate_card": ["read", "write"], "billable_metric": ["read", "write"], "payment_receipt": ["read", "write"], "payment_request": ["read", "write"], "x402_connection": ["read", "write"], "product_category": ["read", "write"], "webhook_endpoint": ["read", "write"], "contract_rate_card": ["read", "write"], "wallet_transaction": ["read", "write"], "invoice_custom_section": ["read", "write"], "usage_attribution_type": ["read", "write"], "webhook_jwt_public_key": ["read", "write"]}], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000071"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (6, 'Customer', '3b8e8d57-1a9b-42b9-9de2-f2b8a4e9df7d', 'create', NULL, NULL, '{"id": [null, "3b8e8d57-1a9b-42b9-9de2-f2b8a4e9df7d"], "url": [null, "http://gerhold-leannon.test/crysta"], "city": [null, "Paris"], "name": [null, "Percentage Customer Co."], "slug": [null, "CON-0071-001"], "email": [null, "percentage@example.invalid"], "phone": [null, "(539) 467 7734"], "state": [null, "IDF"], "country": [null, "FR"], "zipcode": [null, "75011"], "currency": [null, "EUR"], "lastname": [null, "Feest"], "logo_url": [null, "http://stiedemann.test/mikki"], "firstname": [null, "Dawna"], "created_at": [null, "2025-05-02T11:00:00.000Z"], "legal_name": [null, "O''Connell-Ernser"], "updated_at": [null, "2025-05-02T11:00:00.000Z"], "external_id": [null, "percentage-customer-1"], "legal_number": [null, "85-864-9317"], "address_line1": [null, "11 Rue des Pourcentages"], "address_line2": [null, "Suite 537"], "sequential_id": [null, 1], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000071"], "billing_entity_id": [null, "0c0223ac-d123-4e4d-a7f4-d2332e4f1832"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (7, 'BillableMetric', '1a4a0d6e-0000-4000-8000-000000000072', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000072"], "code": [null, "charged_calls"], "name": [null, "Charged API Calls"], "created_at": [null, "2025-05-02T11:00:00.000Z"], "expression": [null, ""], "field_name": [null, "calls"], "updated_at": [null, "2025-05-02T11:00:00.000Z"], "description": [null, "seeded sum metric for the percentage charge"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000071"], "aggregation_type": [null, "sum_agg"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (8, 'Plan', '6acfe671-1f18-4afc-9d81-40ce3d4de31f', 'create', NULL, NULL, '{"id": [null, "6acfe671-1f18-4afc-9d81-40ce3d4de31f"], "code": [null, "percentage-plan"], "name": [null, "Percentage Plan"], "interval": [null, "monthly"], "created_at": [null, "2025-05-02T11:00:00.000Z"], "updated_at": [null, "2025-05-02T11:00:00.000Z"], "description": [null, "arrears plan with a percentage charge"], "amount_cents": [null, 4900], "amount_currency": [null, "EUR"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000071"], "invoice_display_name": [null, "Percentage Plan Display"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (9, 'Charge', '1a4a0d6e-0000-4000-8000-000000000073', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000073"], "code": [null, "percentage-charge"], "plan_id": [null, "6acfe671-1f18-4afc-9d81-40ce3d4de31f"], "created_at": [null, "2025-05-02T11:00:00.000Z"], "properties": ["{}", {"rate": "1.3", "fixed_amount": "2.0", "free_units_per_events": 3, "per_transaction_max_amount": null, "per_transaction_min_amount": null, "free_units_per_total_aggregation": "250.0"}], "updated_at": [null, "2025-05-02T11:00:00.000Z"], "charge_model": ["standard", "percentage"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000071"], "billable_metric_id": [null, "1a4a0d6e-0000-4000-8000-000000000072"], "invoice_display_name": [null, "Charged API calls"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (10, 'Plan', '6acfe671-1f18-4afc-9d81-40ce3d4de31f', 'update', NULL, '{"id": "6acfe671-1f18-4afc-9d81-40ce3d4de31f", "code": "percentage-plan", "name": "Percentage Plan", "interval": "monthly", "parent_id": null, "created_at": "2025-05-02T11:00:00.000Z", "deleted_at": null, "updated_at": "2025-05-02T11:00:00.000Z", "description": "arrears plan with a percentage charge", "amount_cents": 4900, "trial_period": null, "pay_in_advance": false, "amount_currency": "EUR", "organization_id": "1a4a0d6e-0000-4000-8000-000000000071", "pending_deletion": false, "bill_charges_monthly": null, "invoice_display_name": "Percentage Plan Display", "bill_fixed_charges_monthly": false}', NULL, '2025-05-02 11:00:00', 'test');


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


