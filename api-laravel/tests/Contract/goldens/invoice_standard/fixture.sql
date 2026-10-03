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

INSERT INTO public.organizations VALUES ('1a4a0d6e-0000-4000-8000-000000000041', 'Contract Invoices Org', '2025-06-05 11:00:00', '2025-06-05 11:00:00', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'ollie@ledner.example', NULL, NULL, NULL, NULL, NULL, 0, 'UTC', 'en', '{invoice.finalized,credit_note.created}', NULL, 0, 'USD', 0, 'CON-0041', false, '{}', false, true, false, '8e873261-d2a6-4198-89af-c233a1c86558', '{email_password,google_oauth}', NULL, false, false, '{}', NULL, 'contract-invoices-org');


--
-- Data for Name: add_ons; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.add_ons VALUES ('1a4a0d6e-0000-4000-8000-000000000042', '1a4a0d6e-0000-4000-8000-000000000041', 'Seeded Setup Add-on', 'setup-fee', 'seeded add-on', 1500, 'EUR', '2025-06-05 11:00:00', '2025-06-05 11:00:00', NULL, 'Setup fee');


--
-- Data for Name: taxes; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.taxes VALUES ('373a4566-16a3-41d5-a0e1-d457d47b08aa', '1a4a0d6e-0000-4000-8000-000000000041', 'French Standard VAT', 'preview-vat-20', 'Preview VAT', 20, '2025-06-05 11:00:00', '2025-06-05 11:00:00', false, false, NULL);


--
-- Data for Name: add_ons_taxes; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: dunning_campaigns; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: billing_entities; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.billing_entities VALUES ('652f982d-a966-4109-9d38-9987017c5c0d', '1a4a0d6e-0000-4000-8000-000000000041', NULL, NULL, NULL, NULL, NULL, NULL, 'UTC', 'USD', 'en', 'WAL-5C0D', 'per_customer', true, NULL, 0, 0, 'marci@kirlin.test', '{invoice.finalized,credit_note.created}', false, NULL, NULL, NULL, 'Walter, Langosh and Feil', 'entity_f22f1ea0-1c28-4db7-9b6e-c77243c82144', NULL, 0, NULL, NULL, '2025-06-05 11:00:00', '2025-06-05 11:00:00', NULL, false, 'next_period_start', 'align_with_finalization_date', NULL, NULL);


--
-- Data for Name: customers; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.customers VALUES ('449be0df-5244-4d4a-be54-013b56980f73', 'preview-customer-1', 'Preview Customer Co.', '1a4a0d6e-0000-4000-8000-000000000041', '2025-06-05 11:00:00', '2025-06-05 11:00:00', 'FR', '5 Rue de la Facture', 'Apt. 741', 'IDF', '75003', 'preview@example.invalid', 'Paris', 'http://grant.test/lanny', '610.345.4021', 'http://runolfsson-fahey.test/monica_botsford', 'Ritchie Group', '42-045-8516', NULL, NULL, 'CON-0041-001', 1, 'EUR', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'Frieda', 'Hegmann', NULL, NULL, false, 0, NULL, false, 'customer', '652f982d-a966-4109-9d38-9987017c5c0d', 0, NULL, NULL, false, '{}', NULL);


--
-- Data for Name: applied_add_ons; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: billable_metrics; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.billable_metrics VALUES ('1a4a0d6e-0000-4000-8000-000000000043', '1a4a0d6e-0000-4000-8000-000000000041', 'Seeded API Calls', 'api_calls', 'seeded sum metric', '{}', 1, '2025-06-05 11:00:00', '2025-06-05 11:00:00', 'calls', NULL, false, NULL, NULL, '', NULL, NULL);


--
-- Data for Name: catalog_plans; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: plans; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.plans VALUES ('204abc8d-e588-496a-aa50-425a7f84237e', '1a4a0d6e-0000-4000-8000-000000000041', 'Invoiced Plan', '2025-06-05 11:00:00', '2025-06-05 11:00:00', 'invoiced-plan', 1, 'plan for the preview', 4900, 'EUR', NULL, false, NULL, NULL, NULL, false, 'Invoiced Plan Display', false);


--
-- Data for Name: charges; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.charges VALUES ('f22dff06-68cf-4bfa-bade-db3542d2e780', '1a4a0d6e-0000-4000-8000-000000000043', '2025-06-05 11:00:00', '2025-06-05 11:00:00', '204abc8d-e588-496a-aa50-425a7f84237e', NULL, 0, '{"amount": "0.10"}', NULL, false, 0, true, false, 'API calls', NULL, NULL, '1a4a0d6e-0000-4000-8000-000000000041', 'api-calls-charge', false);


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

INSERT INTO public.api_keys VALUES ('1d33ae2d-8a01-4c55-a59d-8a9e45a900d0', '1a4a0d6e-0000-4000-8000-000000000041', '1a4a0d6e-0000-4000-8000-0000000000ee', '2025-06-05 11:00:00', '2025-06-05 11:00:00', NULL, NULL, 'Invoices Capture Key', '{"fee": ["read", "write"], "tax": ["read", "write"], "plan": ["read", "write"], "x402": ["read", "write"], "alert": ["read", "write"], "event": ["read", "write"], "order": ["read", "write"], "quote": ["read", "write"], "add_on": ["read", "write"], "coupon": ["read", "write"], "wallet": ["read", "write"], "api_log": ["read", "write"], "feature": ["read", "write"], "invoice": ["read", "write"], "payment": ["read", "write"], "product": ["read", "write"], "analytic": ["read", "write"], "contract": ["read", "write"], "customer": ["read", "write"], "rate_card": ["read", "write"], "order_form": ["read", "write"], "credit_note": ["read", "write"], "activity_log": ["read", "write"], "organization": ["read", "write"], "security_log": ["read", "write"], "subscription": ["read", "write"], "applied_coupon": ["read", "write"], "billing_entity": ["read", "write"], "customer_usage": ["read", "write"], "lifetime_usage": ["read", "write"], "payment_method": ["read", "write"], "plan_rate_card": ["read", "write"], "billable_metric": ["read", "write"], "payment_receipt": ["read", "write"], "payment_request": ["read", "write"], "x402_connection": ["read", "write"], "product_category": ["read", "write"], "webhook_endpoint": ["read", "write"], "contract_rate_card": ["read", "write"], "wallet_transaction": ["read", "write"], "invoice_custom_section": ["read", "write"], "usage_attribution_type": ["read", "write"], "webhook_jwt_public_key": ["read", "write"]}');


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

INSERT INTO public.customers_taxes VALUES ('f184753f-08af-4a13-9011-0f22e1ab975c', '449be0df-5244-4d4a-be54-013b56980f73', '373a4566-16a3-41d5-a0e1-d457d47b08aa', '2025-06-05 11:00:00', '2025-06-05 11:00:00', '1a4a0d6e-0000-4000-8000-000000000041');


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

INSERT INTO public.versions VALUES (1, 'Organization', '1a4a0d6e-0000-4000-8000-000000000041', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000041"], "name": [null, "Contract Invoices Org"], "slug": [null, "contract-invoices-org"], "email": [null, "ollie@ledner.example"], "hmac_key": [null, "8e873261-d2a6-4198-89af-c233a1c86558"], "created_at": [null, "2025-06-05T11:00:00.000Z"], "updated_at": [null, "2025-06-05T11:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "audit_logs_period": [30, null]}', '2025-06-05 11:00:00', 'test');
INSERT INTO public.versions VALUES (2, 'BillingEntity', '652f982d-a966-4109-9d38-9987017c5c0d', 'create', NULL, NULL, '{"id": [null, "652f982d-a966-4109-9d38-9987017c5c0d"], "code": [null, "entity_f22f1ea0-1c28-4db7-9b6e-c77243c82144"], "name": [null, "Walter, Langosh and Feil"], "email": [null, "marci@kirlin.test"], "created_at": [null, "2025-06-05T11:00:00.000Z"], "updated_at": [null, "2025-06-05T11:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000041"]}', '2025-06-05 11:00:00', 'test');
INSERT INTO public.versions VALUES (3, 'BillingEntity', '652f982d-a966-4109-9d38-9987017c5c0d', 'update', NULL, '{"id": "652f982d-a966-4109-9d38-9987017c5c0d", "city": null, "code": "entity_f22f1ea0-1c28-4db7-9b6e-c77243c82144", "logo": null, "name": "Walter, Langosh and Feil", "email": "marci@kirlin.test", "phone": null, "state": null, "country": null, "zipcode": null, "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-06-05T11:00:00.000Z", "deleted_at": null, "einvoicing": false, "legal_name": null, "updated_at": "2025-06-05T11:00:00.000Z", "archived_at": null, "legal_number": null, "payment_term": null, "address_line1": null, "address_line2": null, "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "organization_id": "1a4a0d6e-0000-4000-8000-000000000041", "default_currency": "USD", "net_payment_term": 0, "eu_tax_management": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "document_number_prefix": null, "tax_identification_number": null, "applied_dunning_campaign_id": null, "finalize_zero_amount_invoice": true, "subscription_invoice_issuing_date_anchor": "next_period_start", "subscription_invoice_issuing_date_adjustment": "align_with_finalization_date"}', '{"document_number_prefix": [null, "WAL-5C0D"]}', '2025-06-05 11:00:00', 'test');
INSERT INTO public.versions VALUES (4, 'Organization', '1a4a0d6e-0000-4000-8000-000000000041', 'update', NULL, '{"id": "1a4a0d6e-0000-4000-8000-000000000041", "city": null, "logo": null, "name": "Contract Invoices Org", "slug": "contract-invoices-org", "email": "ollie@ledner.example", "state": null, "api_key": null, "country": null, "zipcode": null, "hmac_key": "8e873261-d2a6-4198-89af-c233a1c86558", "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-06-05T11:00:00.000Z", "legal_name": null, "updated_at": "2025-06-05T11:00:00.000Z", "max_wallets": null, "webhook_url": null, "legal_number": null, "address_line1": null, "address_line2": null, "feature_flags": [], "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "default_currency": "USD", "net_payment_term": 0, "audit_logs_period": null, "eu_tax_management": false, "custom_aggregation": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "premium_integrations": [], "authentication_methods": ["email_password", "google_oauth"], "document_number_prefix": null, "clickhouse_events_store": false, "tax_identification_number": null, "finalize_zero_amount_invoice": true, "clickhouse_deduplication_enabled": false}', '{"document_number_prefix": [null, "CON-0041"]}', '2025-06-05 11:00:00', 'test');
INSERT INTO public.versions VALUES (5, 'ApiKey', '1d33ae2d-8a01-4c55-a59d-8a9e45a900d0', 'create', NULL, NULL, '{"id": [null, "1d33ae2d-8a01-4c55-a59d-8a9e45a900d0"], "name": [null, "Invoices Capture Key"], "value": [null, "7463aa1d-ada7-4cb3-8393-e58ed6b40874"], "created_at": [null, "2025-06-05T11:00:00.000Z"], "updated_at": [null, "2025-06-05T11:00:00.000Z"], "permissions": [null, {"fee": ["read", "write"], "tax": ["read", "write"], "plan": ["read", "write"], "x402": ["read", "write"], "alert": ["read", "write"], "event": ["read", "write"], "order": ["read", "write"], "quote": ["read", "write"], "add_on": ["read", "write"], "coupon": ["read", "write"], "wallet": ["read", "write"], "api_log": ["read", "write"], "feature": ["read", "write"], "invoice": ["read", "write"], "payment": ["read", "write"], "product": ["read", "write"], "analytic": ["read", "write"], "contract": ["read", "write"], "customer": ["read", "write"], "rate_card": ["read", "write"], "order_form": ["read", "write"], "credit_note": ["read", "write"], "activity_log": ["read", "write"], "organization": ["read", "write"], "security_log": ["read", "write"], "subscription": ["read", "write"], "applied_coupon": ["read", "write"], "billing_entity": ["read", "write"], "customer_usage": ["read", "write"], "lifetime_usage": ["read", "write"], "payment_method": ["read", "write"], "plan_rate_card": ["read", "write"], "billable_metric": ["read", "write"], "payment_receipt": ["read", "write"], "payment_request": ["read", "write"], "x402_connection": ["read", "write"], "product_category": ["read", "write"], "webhook_endpoint": ["read", "write"], "contract_rate_card": ["read", "write"], "wallet_transaction": ["read", "write"], "invoice_custom_section": ["read", "write"], "usage_attribution_type": ["read", "write"], "webhook_jwt_public_key": ["read", "write"]}], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000041"]}', '2025-06-05 11:00:00', 'test');
INSERT INTO public.versions VALUES (6, 'AddOn', '1a4a0d6e-0000-4000-8000-000000000042', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000042"], "code": [null, "setup-fee"], "name": [null, "Seeded Setup Add-on"], "created_at": [null, "2025-06-05T11:00:00.000Z"], "updated_at": [null, "2025-06-05T11:00:00.000Z"], "description": [null, "seeded add-on"], "amount_cents": [null, 1500], "amount_currency": [null, "EUR"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000041"], "invoice_display_name": [null, "Setup fee"]}', '2025-06-05 11:00:00', 'test');
INSERT INTO public.versions VALUES (7, 'BillableMetric', '1a4a0d6e-0000-4000-8000-000000000043', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000043"], "code": [null, "api_calls"], "name": [null, "Seeded API Calls"], "created_at": [null, "2025-06-05T11:00:00.000Z"], "expression": [null, ""], "field_name": [null, "calls"], "updated_at": [null, "2025-06-05T11:00:00.000Z"], "description": [null, "seeded sum metric"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000041"], "aggregation_type": [null, "sum_agg"]}', '2025-06-05 11:00:00', 'test');
INSERT INTO public.versions VALUES (8, 'Plan', '204abc8d-e588-496a-aa50-425a7f84237e', 'create', NULL, NULL, '{"id": [null, "204abc8d-e588-496a-aa50-425a7f84237e"], "code": [null, "invoiced-plan"], "name": [null, "Invoiced Plan"], "interval": [null, "monthly"], "created_at": [null, "2025-06-05T11:00:00.000Z"], "updated_at": [null, "2025-06-05T11:00:00.000Z"], "description": [null, "plan for the preview"], "amount_cents": [null, 4900], "amount_currency": [null, "EUR"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000041"], "invoice_display_name": [null, "Invoiced Plan Display"]}', '2025-06-05 11:00:00', 'test');
INSERT INTO public.versions VALUES (9, 'Charge', 'f22dff06-68cf-4bfa-bade-db3542d2e780', 'create', NULL, NULL, '{"id": [null, "f22dff06-68cf-4bfa-bade-db3542d2e780"], "code": [null, "api-calls-charge"], "plan_id": [null, "204abc8d-e588-496a-aa50-425a7f84237e"], "created_at": [null, "2025-06-05T11:00:00.000Z"], "properties": ["{}", {"amount": "0.10"}], "updated_at": [null, "2025-06-05T11:00:00.000Z"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000041"], "billable_metric_id": [null, "1a4a0d6e-0000-4000-8000-000000000043"], "invoice_display_name": [null, "API calls"]}', '2025-06-05 11:00:00', 'test');
INSERT INTO public.versions VALUES (10, 'Plan', '204abc8d-e588-496a-aa50-425a7f84237e', 'update', NULL, '{"id": "204abc8d-e588-496a-aa50-425a7f84237e", "code": "invoiced-plan", "name": "Invoiced Plan", "interval": "monthly", "parent_id": null, "created_at": "2025-06-05T11:00:00.000Z", "deleted_at": null, "updated_at": "2025-06-05T11:00:00.000Z", "description": "plan for the preview", "amount_cents": 4900, "trial_period": null, "pay_in_advance": false, "amount_currency": "EUR", "organization_id": "1a4a0d6e-0000-4000-8000-000000000041", "pending_deletion": false, "bill_charges_monthly": null, "invoice_display_name": "Invoiced Plan Display", "bill_fixed_charges_monthly": false}', NULL, '2025-06-05 11:00:00', 'test');
INSERT INTO public.versions VALUES (11, 'Tax', '373a4566-16a3-41d5-a0e1-d457d47b08aa', 'create', NULL, NULL, '{"id": [null, "373a4566-16a3-41d5-a0e1-d457d47b08aa"], "code": [null, "preview-vat-20"], "name": [null, "Preview VAT"], "rate": [0.0, 20.0], "created_at": [null, "2025-06-05T11:00:00.000Z"], "updated_at": [null, "2025-06-05T11:00:00.000Z"], "description": [null, "French Standard VAT"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000041"]}', '2025-06-05 11:00:00', 'test');
INSERT INTO public.versions VALUES (12, 'Customer', '449be0df-5244-4d4a-be54-013b56980f73', 'create', NULL, NULL, '{"id": [null, "449be0df-5244-4d4a-be54-013b56980f73"], "url": [null, "http://grant.test/lanny"], "city": [null, "Paris"], "name": [null, "Preview Customer Co."], "slug": [null, "CON-0041-001"], "email": [null, "preview@example.invalid"], "phone": [null, "610.345.4021"], "state": [null, "IDF"], "country": [null, "FR"], "zipcode": [null, "75003"], "currency": [null, "EUR"], "lastname": [null, "Hegmann"], "logo_url": [null, "http://runolfsson-fahey.test/monica_botsford"], "firstname": [null, "Frieda"], "created_at": [null, "2025-06-05T11:00:00.000Z"], "legal_name": [null, "Ritchie Group"], "updated_at": [null, "2025-06-05T11:00:00.000Z"], "external_id": [null, "preview-customer-1"], "legal_number": [null, "42-045-8516"], "address_line1": [null, "5 Rue de la Facture"], "address_line2": [null, "Apt. 741"], "sequential_id": [null, 1], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000041"], "billing_entity_id": [null, "652f982d-a966-4109-9d38-9987017c5c0d"]}', '2025-06-05 11:00:00', 'test');
INSERT INTO public.versions VALUES (13, 'Customer::AppliedTax', 'f184753f-08af-4a13-9011-0f22e1ab975c', 'create', NULL, NULL, '{"id": [null, "f184753f-08af-4a13-9011-0f22e1ab975c"], "tax_id": [null, "373a4566-16a3-41d5-a0e1-d457d47b08aa"], "created_at": [null, "2025-06-05T11:00:00.000Z"], "updated_at": [null, "2025-06-05T11:00:00.000Z"], "customer_id": [null, "449be0df-5244-4d4a-be54-013b56980f73"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000041"]}', '2025-06-05 11:00:00', 'test');


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

SELECT pg_catalog.setval('public.versions_id_seq', 13, true);


--
-- PostgreSQL database dump complete
--


