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

INSERT INTO public.organizations VALUES ('1a4a0d6e-0000-4000-8000-000000000011', 'Contract Customers Org', '2025-06-02 12:00:00', '2025-06-02 12:00:00', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'jim@gutkowski.test', NULL, NULL, NULL, NULL, NULL, 0, 'UTC', 'en', '{invoice.finalized,credit_note.created}', NULL, 0, 'USD', 0, 'CON-0011', false, '{}', false, true, false, '795032d4-58db-4857-bd1a-2ce5f7232a0b', '{email_password,google_oauth}', NULL, false, false, '{}', NULL, 'contract-customers-org');


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

INSERT INTO public.billing_entities VALUES ('5a0fe646-9b59-41e3-8476-1e927e01931f', '1a4a0d6e-0000-4000-8000-000000000011', NULL, NULL, NULL, NULL, NULL, NULL, 'UTC', 'USD', 'en', 'HIL-931F', 'per_customer', true, NULL, 0, 0, 'omar@mcdermott.example', '{invoice.finalized,credit_note.created}', false, NULL, NULL, NULL, 'Hilll-Leannon', 'entity_9e398b39-167b-4cb0-8778-95c83c55e3d6', NULL, 0, NULL, NULL, '2025-06-02 12:00:00', '2025-06-02 12:00:00', NULL, false, 'next_period_start', 'align_with_finalization_date', NULL, NULL);


--
-- Data for Name: customers; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.customers VALUES ('2eb6f561-1d68-4c69-863c-0910f4ce8303', 'seed-customer-1', 'Seeded Customer Co.', '1a4a0d6e-0000-4000-8000-000000000011', '2025-06-02 12:00:00', '2025-06-02 12:00:00', 'FR', '10 Rue du Seed', 'Apt 1', 'IDF', '75001', 'seeded@example.invalid', 'Paris', 'https://seeded.example.invalid', '+33-1-00-00-00-00', 'http://dickinson-wintheiser.example/wai_flatley', NULL, NULL, NULL, NULL, 'CON-0011-001', 1, 'EUR', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'Seed', 'Ed', 'individual', NULL, false, 0, NULL, false, 'customer', '5a0fe646-9b59-41e3-8476-1e927e01931f', 0, NULL, NULL, false, '{}', NULL);


--
-- Data for Name: applied_add_ons; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: billable_metrics; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: catalog_plans; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: plans; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: charges; Type: TABLE DATA; Schema: public; Owner: postgres
--



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

INSERT INTO public.api_keys VALUES ('5f48267e-7be2-45c6-ac1f-92fadc26ba32', '1a4a0d6e-0000-4000-8000-000000000011', '1a4a0d6e-0000-4000-8000-0000000000bb', '2025-06-02 12:00:00', '2025-06-02 12:00:00', NULL, NULL, 'Customers Capture Key', '{"fee": ["read", "write"], "tax": ["read", "write"], "plan": ["read", "write"], "x402": ["read", "write"], "alert": ["read", "write"], "event": ["read", "write"], "order": ["read", "write"], "quote": ["read", "write"], "add_on": ["read", "write"], "coupon": ["read", "write"], "wallet": ["read", "write"], "api_log": ["read", "write"], "feature": ["read", "write"], "invoice": ["read", "write"], "payment": ["read", "write"], "product": ["read", "write"], "analytic": ["read", "write"], "contract": ["read", "write"], "customer": ["read", "write"], "rate_card": ["read", "write"], "order_form": ["read", "write"], "credit_note": ["read", "write"], "activity_log": ["read", "write"], "organization": ["read", "write"], "security_log": ["read", "write"], "subscription": ["read", "write"], "applied_coupon": ["read", "write"], "billing_entity": ["read", "write"], "customer_usage": ["read", "write"], "lifetime_usage": ["read", "write"], "payment_method": ["read", "write"], "plan_rate_card": ["read", "write"], "billable_metric": ["read", "write"], "payment_receipt": ["read", "write"], "payment_request": ["read", "write"], "x402_connection": ["read", "write"], "product_category": ["read", "write"], "webhook_endpoint": ["read", "write"], "contract_rate_card": ["read", "write"], "wallet_transaction": ["read", "write"], "invoice_custom_section": ["read", "write"], "usage_attribution_type": ["read", "write"], "webhook_jwt_public_key": ["read", "write"]}');


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

INSERT INTO public.versions VALUES (1, 'Organization', '1a4a0d6e-0000-4000-8000-000000000011', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000011"], "name": [null, "Contract Customers Org"], "slug": [null, "contract-customers-org"], "email": [null, "jim@gutkowski.test"], "hmac_key": [null, "795032d4-58db-4857-bd1a-2ce5f7232a0b"], "created_at": [null, "2025-06-02T12:00:00.000Z"], "updated_at": [null, "2025-06-02T12:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "audit_logs_period": [30, null]}', '2025-06-02 12:00:00', 'test');
INSERT INTO public.versions VALUES (2, 'BillingEntity', '5a0fe646-9b59-41e3-8476-1e927e01931f', 'create', NULL, NULL, '{"id": [null, "5a0fe646-9b59-41e3-8476-1e927e01931f"], "code": [null, "entity_9e398b39-167b-4cb0-8778-95c83c55e3d6"], "name": [null, "Hilll-Leannon"], "email": [null, "omar@mcdermott.example"], "created_at": [null, "2025-06-02T12:00:00.000Z"], "updated_at": [null, "2025-06-02T12:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000011"]}', '2025-06-02 12:00:00', 'test');
INSERT INTO public.versions VALUES (3, 'BillingEntity', '5a0fe646-9b59-41e3-8476-1e927e01931f', 'update', NULL, '{"id": "5a0fe646-9b59-41e3-8476-1e927e01931f", "city": null, "code": "entity_9e398b39-167b-4cb0-8778-95c83c55e3d6", "logo": null, "name": "Hilll-Leannon", "email": "omar@mcdermott.example", "phone": null, "state": null, "country": null, "zipcode": null, "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-06-02T12:00:00.000Z", "deleted_at": null, "einvoicing": false, "legal_name": null, "updated_at": "2025-06-02T12:00:00.000Z", "archived_at": null, "legal_number": null, "payment_term": null, "address_line1": null, "address_line2": null, "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "organization_id": "1a4a0d6e-0000-4000-8000-000000000011", "default_currency": "USD", "net_payment_term": 0, "eu_tax_management": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "document_number_prefix": null, "tax_identification_number": null, "applied_dunning_campaign_id": null, "finalize_zero_amount_invoice": true, "subscription_invoice_issuing_date_anchor": "next_period_start", "subscription_invoice_issuing_date_adjustment": "align_with_finalization_date"}', '{"document_number_prefix": [null, "HIL-931F"]}', '2025-06-02 12:00:00', 'test');
INSERT INTO public.versions VALUES (4, 'Organization', '1a4a0d6e-0000-4000-8000-000000000011', 'update', NULL, '{"id": "1a4a0d6e-0000-4000-8000-000000000011", "city": null, "logo": null, "name": "Contract Customers Org", "slug": "contract-customers-org", "email": "jim@gutkowski.test", "state": null, "api_key": null, "country": null, "zipcode": null, "hmac_key": "795032d4-58db-4857-bd1a-2ce5f7232a0b", "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-06-02T12:00:00.000Z", "legal_name": null, "updated_at": "2025-06-02T12:00:00.000Z", "max_wallets": null, "webhook_url": null, "legal_number": null, "address_line1": null, "address_line2": null, "feature_flags": [], "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "default_currency": "USD", "net_payment_term": 0, "audit_logs_period": null, "eu_tax_management": false, "custom_aggregation": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "premium_integrations": [], "authentication_methods": ["email_password", "google_oauth"], "document_number_prefix": null, "clickhouse_events_store": false, "tax_identification_number": null, "finalize_zero_amount_invoice": true, "clickhouse_deduplication_enabled": false}', '{"document_number_prefix": [null, "CON-0011"]}', '2025-06-02 12:00:00', 'test');
INSERT INTO public.versions VALUES (5, 'ApiKey', '5f48267e-7be2-45c6-ac1f-92fadc26ba32', 'create', NULL, NULL, '{"id": [null, "5f48267e-7be2-45c6-ac1f-92fadc26ba32"], "name": [null, "Customers Capture Key"], "value": [null, "164710b1-5c08-45d2-a26e-20d82722c668"], "created_at": [null, "2025-06-02T12:00:00.000Z"], "updated_at": [null, "2025-06-02T12:00:00.000Z"], "permissions": [null, {"fee": ["read", "write"], "tax": ["read", "write"], "plan": ["read", "write"], "x402": ["read", "write"], "alert": ["read", "write"], "event": ["read", "write"], "order": ["read", "write"], "quote": ["read", "write"], "add_on": ["read", "write"], "coupon": ["read", "write"], "wallet": ["read", "write"], "api_log": ["read", "write"], "feature": ["read", "write"], "invoice": ["read", "write"], "payment": ["read", "write"], "product": ["read", "write"], "analytic": ["read", "write"], "contract": ["read", "write"], "customer": ["read", "write"], "rate_card": ["read", "write"], "order_form": ["read", "write"], "credit_note": ["read", "write"], "activity_log": ["read", "write"], "organization": ["read", "write"], "security_log": ["read", "write"], "subscription": ["read", "write"], "applied_coupon": ["read", "write"], "billing_entity": ["read", "write"], "customer_usage": ["read", "write"], "lifetime_usage": ["read", "write"], "payment_method": ["read", "write"], "plan_rate_card": ["read", "write"], "billable_metric": ["read", "write"], "payment_receipt": ["read", "write"], "payment_request": ["read", "write"], "x402_connection": ["read", "write"], "product_category": ["read", "write"], "webhook_endpoint": ["read", "write"], "contract_rate_card": ["read", "write"], "wallet_transaction": ["read", "write"], "invoice_custom_section": ["read", "write"], "usage_attribution_type": ["read", "write"], "webhook_jwt_public_key": ["read", "write"]}], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000011"]}', '2025-06-02 12:00:00', 'test');
INSERT INTO public.versions VALUES (6, 'Customer', '2eb6f561-1d68-4c69-863c-0910f4ce8303', 'create', NULL, NULL, '{"id": [null, "2eb6f561-1d68-4c69-863c-0910f4ce8303"], "url": [null, "https://seeded.example.invalid"], "city": [null, "Paris"], "name": [null, "Seeded Customer Co."], "slug": [null, "CON-0011-001"], "email": [null, "seeded@example.invalid"], "phone": [null, "+33-1-00-00-00-00"], "state": [null, "IDF"], "country": [null, "FR"], "zipcode": [null, "75001"], "currency": [null, "EUR"], "lastname": [null, "Ed"], "logo_url": [null, "http://dickinson-wintheiser.example/wai_flatley"], "firstname": [null, "Seed"], "created_at": [null, "2025-06-02T12:00:00.000Z"], "updated_at": [null, "2025-06-02T12:00:00.000Z"], "external_id": [null, "seed-customer-1"], "address_line1": [null, "10 Rue du Seed"], "address_line2": [null, "Apt 1"], "customer_type": [null, "individual"], "sequential_id": [null, 1], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000011"], "billing_entity_id": [null, "5a0fe646-9b59-41e3-8476-1e927e01931f"]}', '2025-06-02 12:00:00', 'test');


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

SELECT pg_catalog.setval('public.versions_id_seq', 6, true);


--
-- PostgreSQL database dump complete
--


