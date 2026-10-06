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

INSERT INTO public.organizations VALUES ('1a4a0d6e-0000-4000-8000-0000000000a5', 'Contract Invoiced Usage Org', '2025-06-12 11:00:00', '2025-06-12 11:00:00', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'eusebio@herman.test', NULL, NULL, NULL, NULL, NULL, 0, 'UTC', 'en', '{invoice.finalized,credit_note.created}', NULL, 0, 'USD', 0, 'CON-00A5', false, '{}', false, true, false, '050b9ffc-c056-4355-8391-8716c3cb5ffc', '{email_password,google_oauth}', NULL, false, false, '{}', NULL, 'contract-invoiced-usage-org');


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

INSERT INTO public.billing_entities VALUES ('6bd0c144-384d-48f8-96d3-49643704948d', '1a4a0d6e-0000-4000-8000-0000000000a5', NULL, NULL, NULL, NULL, NULL, NULL, 'UTC', 'USD', 'en', 'PFE-948D', 'per_customer', true, NULL, 0, 0, 'nydia.larson@keeling.example', '{invoice.finalized,credit_note.created}', false, NULL, NULL, NULL, 'Pfeffer Inc', 'entity_fbc65142-15c3-49f3-a69e-825808c86998', NULL, 0, NULL, NULL, '2025-06-12 11:00:00', '2025-06-12 11:00:00', NULL, false, 'next_period_start', 'align_with_finalization_date', NULL, NULL);


--
-- Data for Name: customers; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.customers VALUES ('0526fcab-6053-4a2d-811e-388aaeb7c9b2', 'invoiced-usage-cust-1', 'Invoiced Usage Customer', '1a4a0d6e-0000-4000-8000-0000000000a5', '2025-06-12 11:00:00', '2025-06-12 11:00:00', 'BY', '3488 Thea Union', 'Suite 878', 'Illinois', '69544', 'milan_schneider@kreiger.example', 'New Chesterton', 'http://bogisich.test/pansy_hoeger', '331.967.6921', 'http://reichert.test/marcelino', 'Kozey Inc', '72-232-7454', NULL, NULL, 'CON-00A5-001', 1, 'EUR', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'Johnson', 'Price', NULL, NULL, false, 0, NULL, false, 'customer', '6bd0c144-384d-48f8-96d3-49643704948d', 0, NULL, NULL, false, '{}', NULL);


--
-- Data for Name: applied_add_ons; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: billable_metrics; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.billable_metrics VALUES ('1a4a0d6e-0000-4000-8000-0000000000a6', '1a4a0d6e-0000-4000-8000-0000000000a5', 'API Calls', 'api_calls', 'some description', '{}', 1, '2025-06-12 11:00:00', '2025-06-12 11:00:00', 'calls', NULL, false, NULL, NULL, '', NULL, NULL);
INSERT INTO public.billable_metrics VALUES ('1a4a0d6e-0000-4000-8000-0000000000a7', '1a4a0d6e-0000-4000-8000-0000000000a5', 'Storage GB', 'storage_gb', 'some description', '{}', 1, '2025-06-12 11:00:00', '2025-06-12 11:00:00', 'gigabytes', NULL, false, NULL, NULL, '', NULL, NULL);


--
-- Data for Name: catalog_plans; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: plans; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.plans VALUES ('43673a2e-6202-4a41-83f2-bbb2a05d4b77', '1a4a0d6e-0000-4000-8000-0000000000a5', 'Invoiced Usage Plan', '2025-06-12 11:00:00', '2025-06-12 11:00:00', 'invoiced-usage-plan', 1, 'Et error quisquam doloribus.', 4900, 'EUR', NULL, false, NULL, NULL, NULL, false, 'Abiquiu', false);


--
-- Data for Name: charges; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.charges VALUES ('1a4a0d6e-0000-4000-8000-0000000000a8', '1a4a0d6e-0000-4000-8000-0000000000a6', '2025-06-12 11:00:00', '2025-06-12 11:00:00', '43673a2e-6202-4a41-83f2-bbb2a05d4b77', NULL, 0, '{"amount": "100"}', NULL, false, 0, true, false, 'Mountains of Terror', NULL, NULL, '1a4a0d6e-0000-4000-8000-0000000000a5', 'api-calls-charge', false);
INSERT INTO public.charges VALUES ('1a4a0d6e-0000-4000-8000-0000000000a9', '1a4a0d6e-0000-4000-8000-0000000000a7', '2025-06-12 11:00:00', '2025-06-12 11:00:00', '43673a2e-6202-4a41-83f2-bbb2a05d4b77', NULL, 0, '{"amount": "50"}', NULL, false, 0, true, false, 'Deeping Coomb', NULL, NULL, '1a4a0d6e-0000-4000-8000-0000000000a5', 'storage-charge', false);


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

INSERT INTO public.invoices VALUES ('b6fe628b-7507-4d92-8b25-06cb0e924d7e', '2025-06-12 11:00:00', '2025-06-12 11:00:00', '2025-06-30', 0, 9200, 0, 0, 'PFE-948D-DRAFT', NULL, NULL, '0526fcab-6053-4a2d-811e-388aaeb7c9b2', 0, 1, 'UTC', 0, true, '1a4a0d6e-0000-4000-8000-0000000000a5', 4, 'EUR', 0, 0, 0, 0, 0, 0, '2025-06-30', 0, NULL, 786988, false, NULL, false, false, 0, 0, NULL, 0, false, NULL, '6bd0c144-384d-48f8-96d3-49643704948d', NULL, NULL, NULL, NULL, '2025-06-11', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO public.invoices VALUES ('44285bfa-6848-4948-ba46-012abfc7d6da', '2025-06-12 11:00:00', '2025-06-12 11:00:00', '2025-07-31', 0, 7000, 0, 0, 'PFE-948D-DRAFT', NULL, NULL, '0526fcab-6053-4a2d-811e-388aaeb7c9b2', 0, 1, 'UTC', 0, true, '1a4a0d6e-0000-4000-8000-0000000000a5', 4, 'EUR', 0, 0, 0, 0, 0, 0, '2025-07-31', 0, NULL, 170474, false, NULL, false, false, 0, 0, NULL, 0, false, NULL, '6bd0c144-384d-48f8-96d3-49643704948d', NULL, NULL, NULL, NULL, '2025-06-11', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);


--
-- Data for Name: rate_card_rates; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: rate_overrides; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: subscriptions; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.subscriptions VALUES ('ef466614-b47b-42b0-8a48-caef9c483eb0', '0526fcab-6053-4a2d-811e-388aaeb7c9b2', '43673a2e-6202-4a41-83f2-bbb2a05d4b77', 1, NULL, NULL, '2025-06-11 11:00:00', '2025-06-12 11:00:00', '2025-06-12 11:00:00', NULL, NULL, 'invoiced-usage-sub-1', 0, '2025-06-11 11:00:00', NULL, NULL, '1a4a0d6e-0000-4000-8000-0000000000a5', NULL, 'generate', NULL, 'provider', false, false, NULL, NULL, NULL, '2025-06-11 11:00:00', NULL, true, false, NULL, NULL, NULL);


--
-- Data for Name: fees; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.fees VALUES ('6ab35901-4eda-478e-9241-b3e39c54d7d9', 'b6fe628b-7507-4d92-8b25-06cb0e924d7e', '1a4a0d6e-0000-4000-8000-0000000000a8', 'ef466614-b47b-42b0-8a48-caef9c483eb0', 5000, 'EUR', 0, 0, '2025-06-10 08:00:00', '2025-06-12 11:00:00', 0.0, NULL, '{"timestamp": "2022-08-01", "to_datetime": "2022-08-31", "from_datetime": "2022-08-01", "charges_duration": 31, "charges_to_datetime": "2022-08-31", "charges_from_datetime": "2022-08-01"}', 0, 'Charge', '1a4a0d6e-0000-4000-8000-0000000000a8', NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, false, 0.00000, 0.0, 'Haleth', 0.000000000000000, '{}', NULL, '{}', NULL, NULL, 200.000000000100000, 2.000000000100000, 1, '1a4a0d6e-0000-4000-8000-0000000000a5', '6bd0c144-384d-48f8-96d3-49643704948d', 0.00000, NULL, false, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO public.fees VALUES ('11e6c2e3-f692-4323-890a-a3e49644d1b6', 'b6fe628b-7507-4d92-8b25-06cb0e924d7e', '1a4a0d6e-0000-4000-8000-0000000000a8', 'ef466614-b47b-42b0-8a48-caef9c483eb0', 3000, 'EUR', 0, 0, '2025-06-20 08:00:00', '2025-06-12 11:00:00', 0.0, NULL, '{"timestamp": "2022-08-01", "to_datetime": "2022-08-31", "from_datetime": "2022-08-01", "charges_duration": 31, "charges_to_datetime": "2022-08-31", "charges_from_datetime": "2022-08-01"}', 0, 'Charge', '1a4a0d6e-0000-4000-8000-0000000000a8', NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, false, 500.00000, 0.0, 'Amras', 0.000000000000000, '{}', NULL, '{}', NULL, NULL, 200.000000000100000, 2.000000000100000, 1, '1a4a0d6e-0000-4000-8000-0000000000a5', '6bd0c144-384d-48f8-96d3-49643704948d', 0.00000, NULL, false, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO public.fees VALUES ('f0af44b3-933c-41d6-8809-ea44c041a888', 'b6fe628b-7507-4d92-8b25-06cb0e924d7e', '1a4a0d6e-0000-4000-8000-0000000000a9', 'ef466614-b47b-42b0-8a48-caef9c483eb0', 1200, 'EUR', 0, 0, '2025-06-25 08:00:00', '2025-06-12 11:00:00', 0.0, NULL, '{"timestamp": "2022-08-01", "to_datetime": "2022-08-31", "from_datetime": "2022-08-01", "charges_duration": 31, "charges_to_datetime": "2022-08-31", "charges_from_datetime": "2022-08-01"}', 0, 'Charge', '1a4a0d6e-0000-4000-8000-0000000000a9', NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, false, 0.00000, 0.0, 'Bilbo Gardner', 0.000000000000000, '{}', NULL, '{}', NULL, NULL, 200.000000000100000, 2.000000000100000, 1, '1a4a0d6e-0000-4000-8000-0000000000a5', '6bd0c144-384d-48f8-96d3-49643704948d', 0.00000, NULL, false, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO public.fees VALUES ('a736a7ac-ba9b-43af-84a8-91b9fd176005', '44285bfa-6848-4948-ba46-012abfc7d6da', '1a4a0d6e-0000-4000-8000-0000000000a8', 'ef466614-b47b-42b0-8a48-caef9c483eb0', 7000, 'EUR', 0, 0, '2025-07-05 08:00:00', '2025-06-12 11:00:00', 0.0, NULL, '{"timestamp": "2022-08-01", "to_datetime": "2022-08-31", "from_datetime": "2022-08-01", "charges_duration": 31, "charges_to_datetime": "2022-08-31", "charges_from_datetime": "2022-08-01"}', 0, 'Charge', '1a4a0d6e-0000-4000-8000-0000000000a8', NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, false, 0.00000, 0.0, 'Morwen', 0.000000000000000, '{}', NULL, '{}', NULL, NULL, 200.000000000100000, 2.000000000100000, 1, '1a4a0d6e-0000-4000-8000-0000000000a5', '6bd0c144-384d-48f8-96d3-49643704948d', 0.00000, NULL, false, NULL, NULL, NULL, NULL, NULL, NULL);


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

INSERT INTO public.api_keys VALUES ('66c17dca-d00f-4da7-b632-2f2f4bc259d5', '1a4a0d6e-0000-4000-8000-0000000000a5', '1a4a0d6e-0000-4000-8000-0000000000b5', '2025-06-12 11:00:00', '2025-06-12 11:00:00', NULL, NULL, 'Invoiced Usage Capture Key', '{"fee": ["read", "write"], "tax": ["read", "write"], "plan": ["read", "write"], "x402": ["read", "write"], "alert": ["read", "write"], "event": ["read", "write"], "order": ["read", "write"], "quote": ["read", "write"], "add_on": ["read", "write"], "coupon": ["read", "write"], "wallet": ["read", "write"], "api_log": ["read", "write"], "feature": ["read", "write"], "invoice": ["read", "write"], "payment": ["read", "write"], "product": ["read", "write"], "analytic": ["read", "write"], "contract": ["read", "write"], "customer": ["read", "write"], "rate_card": ["read", "write"], "order_form": ["read", "write"], "credit_note": ["read", "write"], "activity_log": ["read", "write"], "organization": ["read", "write"], "security_log": ["read", "write"], "subscription": ["read", "write"], "applied_coupon": ["read", "write"], "billing_entity": ["read", "write"], "customer_usage": ["read", "write"], "lifetime_usage": ["read", "write"], "payment_method": ["read", "write"], "plan_rate_card": ["read", "write"], "billable_metric": ["read", "write"], "payment_receipt": ["read", "write"], "payment_request": ["read", "write"], "x402_connection": ["read", "write"], "product_category": ["read", "write"], "webhook_endpoint": ["read", "write"], "contract_rate_card": ["read", "write"], "wallet_transaction": ["read", "write"], "invoice_custom_section": ["read", "write"], "usage_attribution_type": ["read", "write"], "webhook_jwt_public_key": ["read", "write"]}');


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

INSERT INTO public.versions VALUES (1, 'Organization', '1a4a0d6e-0000-4000-8000-0000000000a5', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-0000000000a5"], "name": [null, "Contract Invoiced Usage Org"], "slug": [null, "contract-invoiced-usage-org"], "email": [null, "eusebio@herman.test"], "hmac_key": [null, "050b9ffc-c056-4355-8391-8716c3cb5ffc"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "audit_logs_period": [30, null]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (2, 'BillingEntity', '6bd0c144-384d-48f8-96d3-49643704948d', 'create', NULL, NULL, '{"id": [null, "6bd0c144-384d-48f8-96d3-49643704948d"], "code": [null, "entity_fbc65142-15c3-49f3-a69e-825808c86998"], "name": [null, "Pfeffer Inc"], "email": [null, "nydia.larson@keeling.example"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a5"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (3, 'BillingEntity', '6bd0c144-384d-48f8-96d3-49643704948d', 'update', NULL, '{"id": "6bd0c144-384d-48f8-96d3-49643704948d", "city": null, "code": "entity_fbc65142-15c3-49f3-a69e-825808c86998", "logo": null, "name": "Pfeffer Inc", "email": "nydia.larson@keeling.example", "phone": null, "state": null, "country": null, "zipcode": null, "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-06-12T11:00:00.000Z", "deleted_at": null, "einvoicing": false, "legal_name": null, "updated_at": "2025-06-12T11:00:00.000Z", "archived_at": null, "legal_number": null, "payment_term": null, "address_line1": null, "address_line2": null, "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "organization_id": "1a4a0d6e-0000-4000-8000-0000000000a5", "default_currency": "USD", "net_payment_term": 0, "eu_tax_management": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "document_number_prefix": null, "tax_identification_number": null, "applied_dunning_campaign_id": null, "finalize_zero_amount_invoice": true, "subscription_invoice_issuing_date_anchor": "next_period_start", "subscription_invoice_issuing_date_adjustment": "align_with_finalization_date"}', '{"document_number_prefix": [null, "PFE-948D"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (4, 'Organization', '1a4a0d6e-0000-4000-8000-0000000000a5', 'update', NULL, '{"id": "1a4a0d6e-0000-4000-8000-0000000000a5", "city": null, "logo": null, "name": "Contract Invoiced Usage Org", "slug": "contract-invoiced-usage-org", "email": "eusebio@herman.test", "state": null, "api_key": null, "country": null, "zipcode": null, "hmac_key": "050b9ffc-c056-4355-8391-8716c3cb5ffc", "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-06-12T11:00:00.000Z", "legal_name": null, "updated_at": "2025-06-12T11:00:00.000Z", "max_wallets": null, "webhook_url": null, "legal_number": null, "address_line1": null, "address_line2": null, "feature_flags": [], "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "default_currency": "USD", "net_payment_term": 0, "audit_logs_period": null, "eu_tax_management": false, "custom_aggregation": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "premium_integrations": [], "authentication_methods": ["email_password", "google_oauth"], "document_number_prefix": null, "clickhouse_events_store": false, "tax_identification_number": null, "finalize_zero_amount_invoice": true, "clickhouse_deduplication_enabled": false}', '{"document_number_prefix": [null, "CON-00A5"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (5, 'ApiKey', '66c17dca-d00f-4da7-b632-2f2f4bc259d5', 'create', NULL, NULL, '{"id": [null, "66c17dca-d00f-4da7-b632-2f2f4bc259d5"], "name": [null, "Invoiced Usage Capture Key"], "value": [null, "75e5a03f-55ed-4cf8-af65-1a8cc8e0cb45"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "permissions": [null, {"fee": ["read", "write"], "tax": ["read", "write"], "plan": ["read", "write"], "x402": ["read", "write"], "alert": ["read", "write"], "event": ["read", "write"], "order": ["read", "write"], "quote": ["read", "write"], "add_on": ["read", "write"], "coupon": ["read", "write"], "wallet": ["read", "write"], "api_log": ["read", "write"], "feature": ["read", "write"], "invoice": ["read", "write"], "payment": ["read", "write"], "product": ["read", "write"], "analytic": ["read", "write"], "contract": ["read", "write"], "customer": ["read", "write"], "rate_card": ["read", "write"], "order_form": ["read", "write"], "credit_note": ["read", "write"], "activity_log": ["read", "write"], "organization": ["read", "write"], "security_log": ["read", "write"], "subscription": ["read", "write"], "applied_coupon": ["read", "write"], "billing_entity": ["read", "write"], "customer_usage": ["read", "write"], "lifetime_usage": ["read", "write"], "payment_method": ["read", "write"], "plan_rate_card": ["read", "write"], "billable_metric": ["read", "write"], "payment_receipt": ["read", "write"], "payment_request": ["read", "write"], "x402_connection": ["read", "write"], "product_category": ["read", "write"], "webhook_endpoint": ["read", "write"], "contract_rate_card": ["read", "write"], "wallet_transaction": ["read", "write"], "invoice_custom_section": ["read", "write"], "usage_attribution_type": ["read", "write"], "webhook_jwt_public_key": ["read", "write"]}], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a5"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (6, 'Customer', '0526fcab-6053-4a2d-811e-388aaeb7c9b2', 'create', NULL, NULL, '{"id": [null, "0526fcab-6053-4a2d-811e-388aaeb7c9b2"], "url": [null, "http://bogisich.test/pansy_hoeger"], "city": [null, "New Chesterton"], "name": [null, "Invoiced Usage Customer"], "slug": [null, "CON-00A5-001"], "email": [null, "milan_schneider@kreiger.example"], "phone": [null, "331.967.6921"], "state": [null, "Illinois"], "country": [null, "BY"], "zipcode": [null, "69544"], "currency": [null, "EUR"], "lastname": [null, "Price"], "logo_url": [null, "http://reichert.test/marcelino"], "firstname": [null, "Johnson"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "legal_name": [null, "Kozey Inc"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "external_id": [null, "invoiced-usage-cust-1"], "legal_number": [null, "72-232-7454"], "address_line1": [null, "3488 Thea Union"], "address_line2": [null, "Suite 878"], "sequential_id": [null, 1], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a5"], "billing_entity_id": [null, "6bd0c144-384d-48f8-96d3-49643704948d"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (7, 'BillableMetric', '1a4a0d6e-0000-4000-8000-0000000000a6', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-0000000000a6"], "code": [null, "api_calls"], "name": [null, "API Calls"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "expression": [null, ""], "field_name": [null, "calls"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "description": [null, "some description"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a5"], "aggregation_type": [null, "sum_agg"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (8, 'BillableMetric', '1a4a0d6e-0000-4000-8000-0000000000a7', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-0000000000a7"], "code": [null, "storage_gb"], "name": [null, "Storage GB"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "expression": [null, ""], "field_name": [null, "gigabytes"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "description": [null, "some description"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a5"], "aggregation_type": [null, "sum_agg"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (9, 'Plan', '43673a2e-6202-4a41-83f2-bbb2a05d4b77', 'create', NULL, NULL, '{"id": [null, "43673a2e-6202-4a41-83f2-bbb2a05d4b77"], "code": [null, "invoiced-usage-plan"], "name": [null, "Invoiced Usage Plan"], "interval": [null, "monthly"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "description": [null, "Et error quisquam doloribus."], "amount_cents": [null, 4900], "amount_currency": [null, "EUR"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a5"], "invoice_display_name": [null, "Abiquiu"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (10, 'Charge', '1a4a0d6e-0000-4000-8000-0000000000a8', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-0000000000a8"], "code": [null, "api-calls-charge"], "plan_id": [null, "43673a2e-6202-4a41-83f2-bbb2a05d4b77"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "properties": ["{}", {"amount": "100"}], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a5"], "billable_metric_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a6"], "invoice_display_name": [null, "Mountains of Terror"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (11, 'Plan', '43673a2e-6202-4a41-83f2-bbb2a05d4b77', 'update', NULL, '{"id": "43673a2e-6202-4a41-83f2-bbb2a05d4b77", "code": "invoiced-usage-plan", "name": "Invoiced Usage Plan", "interval": "monthly", "parent_id": null, "created_at": "2025-06-12T11:00:00.000Z", "deleted_at": null, "updated_at": "2025-06-12T11:00:00.000Z", "description": "Et error quisquam doloribus.", "amount_cents": 4900, "trial_period": null, "pay_in_advance": false, "amount_currency": "EUR", "organization_id": "1a4a0d6e-0000-4000-8000-0000000000a5", "pending_deletion": false, "bill_charges_monthly": null, "invoice_display_name": "Abiquiu", "bill_fixed_charges_monthly": false}', NULL, '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (12, 'Charge', '1a4a0d6e-0000-4000-8000-0000000000a9', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-0000000000a9"], "code": [null, "storage-charge"], "plan_id": [null, "43673a2e-6202-4a41-83f2-bbb2a05d4b77"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "properties": ["{}", {"amount": "50"}], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a5"], "billable_metric_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a7"], "invoice_display_name": [null, "Deeping Coomb"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (13, 'Plan', '43673a2e-6202-4a41-83f2-bbb2a05d4b77', 'update', NULL, '{"id": "43673a2e-6202-4a41-83f2-bbb2a05d4b77", "code": "invoiced-usage-plan", "name": "Invoiced Usage Plan", "interval": "monthly", "parent_id": null, "created_at": "2025-06-12T11:00:00.000Z", "deleted_at": null, "updated_at": "2025-06-12T11:00:00.000Z", "description": "Et error quisquam doloribus.", "amount_cents": 4900, "trial_period": null, "pay_in_advance": false, "amount_currency": "EUR", "organization_id": "1a4a0d6e-0000-4000-8000-0000000000a5", "pending_deletion": false, "bill_charges_monthly": null, "invoice_display_name": "Abiquiu", "bill_fixed_charges_monthly": false}', NULL, '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (14, 'Subscription', 'ef466614-b47b-42b0-8a48-caef9c483eb0', 'create', NULL, NULL, '{"id": [null, "ef466614-b47b-42b0-8a48-caef9c483eb0"], "status": [null, "active"], "plan_id": [null, "43673a2e-6202-4a41-83f2-bbb2a05d4b77"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "started_at": [null, "2025-06-11T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "0526fcab-6053-4a2d-811e-388aaeb7c9b2"], "external_id": [null, "invoiced-usage-sub-1"], "activated_at": [null, "2025-06-11T11:00:00.000Z"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a5"], "subscription_at": [null, "2025-06-11T11:00:00.000Z"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (15, 'Invoice', 'b6fe628b-7507-4d92-8b25-06cb0e924d7e', 'create', NULL, NULL, '{"id": [null, "b6fe628b-7507-4d92-8b25-06cb0e924d7e"], "number": ["", "PFE-948D-DRAFT"], "currency": [null, "EUR"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "0526fcab-6053-4a2d-811e-388aaeb7c9b2"], "issuing_date": [null, "2025-06-30"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a5"], "payment_due_date": [null, "2025-06-30"], "billing_entity_id": [null, "6bd0c144-384d-48f8-96d3-49643704948d"], "total_amount_cents": [0, 9200], "expected_finalization_date": [null, "2025-06-11"], "organization_sequential_id": [0, 786988]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (16, 'Invoice', '44285bfa-6848-4948-ba46-012abfc7d6da', 'create', NULL, NULL, '{"id": [null, "44285bfa-6848-4948-ba46-012abfc7d6da"], "number": ["", "PFE-948D-DRAFT"], "currency": [null, "EUR"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "0526fcab-6053-4a2d-811e-388aaeb7c9b2"], "issuing_date": [null, "2025-07-31"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a5"], "payment_due_date": [null, "2025-07-31"], "billing_entity_id": [null, "6bd0c144-384d-48f8-96d3-49643704948d"], "total_amount_cents": [0, 7000], "expected_finalization_date": [null, "2025-06-11"], "organization_sequential_id": [0, 170474]}', '2025-06-12 11:00:00', 'test');


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

SELECT pg_catalog.setval('public.versions_id_seq', 16, true);


--
-- PostgreSQL database dump complete
--


