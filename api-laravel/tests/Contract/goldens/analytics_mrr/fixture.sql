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

INSERT INTO public.organizations VALUES ('1a4a0d6e-0000-4000-8000-0000000000a4', 'Contract MRR Org', '2025-06-12 11:00:00', '2025-06-12 11:00:00', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'alphonso.braun@schuster.test', NULL, NULL, NULL, NULL, NULL, 0, 'UTC', 'en', '{invoice.finalized,credit_note.created}', NULL, 0, 'USD', 0, 'CON-00A4', false, '{}', false, true, false, 'ce8609b1-9249-4dbd-8135-4c380e7cff65', '{email_password,google_oauth}', NULL, false, false, '{}', NULL, 'contract-mrr-org');


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

INSERT INTO public.billing_entities VALUES ('c305db8c-7259-4bc5-a087-2f55448d67d3', '1a4a0d6e-0000-4000-8000-0000000000a4', NULL, NULL, NULL, NULL, NULL, NULL, 'UTC', 'USD', 'en', 'HIN-67D3', 'per_customer', true, NULL, 0, 0, 'alec@rice.example', '{invoice.finalized,credit_note.created}', false, NULL, NULL, NULL, 'Hintz, Connelly and Stanton', 'entity_49eba90d-1d42-4e74-86ec-7864793195dd', NULL, 0, NULL, NULL, '2025-06-12 11:00:00', '2025-06-12 11:00:00', NULL, false, 'next_period_start', 'align_with_finalization_date', NULL, NULL);


--
-- Data for Name: customers; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.customers VALUES ('e5659cf4-5f37-4422-ad18-4b1e9206da53', 'mrr-cust-1', 'MRR Customer', '1a4a0d6e-0000-4000-8000-0000000000a4', '2025-06-12 11:00:00', '2025-06-12 11:00:00', 'CX', '96629 Nada Trail', 'Apt. 186', 'Kentucky', '86159-3732', 'elisha.hayes@jones.test', 'New Louis', 'http://conroy-ferry.test/heriberto.moen', '504.227.9282', 'http://kunze-emard.test/jerome.macejkovic', 'Kilback-Grimes', '99-600-4283', NULL, NULL, 'CON-00A4-001', 1, 'EUR', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'Denny', 'Hirthe', NULL, NULL, false, 0, NULL, false, 'customer', 'c305db8c-7259-4bc5-a087-2f55448d67d3', 0, NULL, NULL, false, '{}', NULL);


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

INSERT INTO public.plans VALUES ('68c01647-66d0-4a41-a26e-23a9bf16dd01', '1a4a0d6e-0000-4000-8000-0000000000a4', 'MRR Monthly Plan', '2025-06-12 11:00:00', '2025-06-12 11:00:00', 'mrr-monthly', 1, 'Et vitae laborum qui.', 4900, 'EUR', NULL, false, NULL, NULL, NULL, false, 'I See You', false);
INSERT INTO public.plans VALUES ('08eda6fb-1728-4f4b-a597-9c3f3ac12f20', '1a4a0d6e-0000-4000-8000-0000000000a4', 'MRR Yearly Plan', '2025-06-12 11:00:00', '2025-06-12 11:00:00', 'mrr-yearly', 2, 'Atque et molestias natus.', 120000, 'EUR', NULL, true, NULL, NULL, NULL, false, 'Thirty-Eight Snub', false);


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

INSERT INTO public.invoices VALUES ('1fd8c8e1-ce76-4b5c-b61d-a30376e9dd89', '2025-06-12 11:00:00', '2025-06-12 11:00:00', '2025-07-01', 0, 4900, 0, 0, 'HIN-67D3-DRAFT', NULL, NULL, 'e5659cf4-5f37-4422-ad18-4b1e9206da53', 0, 1, 'UTC', 0, true, '1a4a0d6e-0000-4000-8000-0000000000a4', 4, 'EUR', 0, 0, 0, 0, 0, 0, '2025-07-01', 0, NULL, 933358, false, NULL, false, false, 0, 0, NULL, 0, false, NULL, 'c305db8c-7259-4bc5-a087-2f55448d67d3', NULL, NULL, NULL, NULL, '2025-06-11', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO public.invoices VALUES ('2114a5c7-6d22-4f09-ac8e-68da30f084c1', '2025-06-12 11:00:00', '2025-06-12 11:00:00', '2025-08-01', 0, 4900, 0, 0, 'HIN-67D3-DRAFT', NULL, NULL, 'e5659cf4-5f37-4422-ad18-4b1e9206da53', 0, 1, 'UTC', 0, true, '1a4a0d6e-0000-4000-8000-0000000000a4', 4, 'EUR', 0, 0, 0, 0, 0, 0, '2025-08-01', 0, NULL, 287916, false, NULL, false, false, 0, 0, NULL, 0, false, NULL, 'c305db8c-7259-4bc5-a087-2f55448d67d3', NULL, NULL, NULL, NULL, '2025-06-11', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO public.invoices VALUES ('9d321c4e-9b57-4931-9a37-64599f0635a2', '2025-06-12 11:00:00', '2025-06-12 11:00:00', '2025-09-01', 0, 4900, 0, 0, 'HIN-67D3-DRAFT', NULL, NULL, 'e5659cf4-5f37-4422-ad18-4b1e9206da53', 0, 1, 'UTC', 0, true, '1a4a0d6e-0000-4000-8000-0000000000a4', 4, 'EUR', 0, 0, 0, 0, 0, 0, '2025-09-01', 0, NULL, 76078, false, NULL, false, false, 0, 0, NULL, 0, false, NULL, 'c305db8c-7259-4bc5-a087-2f55448d67d3', NULL, NULL, NULL, NULL, '2025-06-11', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO public.invoices VALUES ('bc7ada20-afbd-4396-9472-677912eb16ba', '2025-06-12 11:00:00', '2025-06-12 11:00:00', '2025-06-15', 0, 120000, 0, 0, 'HIN-67D3-DRAFT', NULL, NULL, 'e5659cf4-5f37-4422-ad18-4b1e9206da53', 0, 1, 'UTC', 0, true, '1a4a0d6e-0000-4000-8000-0000000000a4', 4, 'EUR', 0, 0, 0, 0, 0, 0, '2025-06-15', 0, NULL, 270878, false, NULL, false, false, 0, 0, NULL, 0, false, NULL, 'c305db8c-7259-4bc5-a087-2f55448d67d3', NULL, NULL, NULL, NULL, '2025-06-11', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);


--
-- Data for Name: rate_card_rates; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: rate_overrides; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: subscriptions; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.subscriptions VALUES ('c2c625de-a6f0-4af2-9599-313ca60a8a47', 'e5659cf4-5f37-4422-ad18-4b1e9206da53', '68c01647-66d0-4a41-a26e-23a9bf16dd01', 1, NULL, NULL, '2025-06-11 11:00:00', '2025-06-12 11:00:00', '2025-06-12 11:00:00', NULL, NULL, 'mrr-sub-monthly', 0, '2025-06-11 11:00:00', NULL, NULL, '1a4a0d6e-0000-4000-8000-0000000000a4', NULL, 'generate', NULL, 'provider', false, false, NULL, NULL, NULL, '2025-06-11 11:00:00', NULL, true, false, NULL, NULL, NULL);
INSERT INTO public.subscriptions VALUES ('72d08146-3fb7-4acb-a36d-85a5a0285b8c', 'e5659cf4-5f37-4422-ad18-4b1e9206da53', '08eda6fb-1728-4f4b-a597-9c3f3ac12f20', 1, NULL, NULL, '2025-06-11 11:00:00', '2025-06-12 11:00:00', '2025-06-12 11:00:00', NULL, NULL, 'mrr-sub-yearly', 0, '2025-06-11 11:00:00', NULL, NULL, '1a4a0d6e-0000-4000-8000-0000000000a4', NULL, 'generate', NULL, 'provider', false, false, NULL, NULL, NULL, '2025-06-11 11:00:00', NULL, true, false, NULL, NULL, NULL);


--
-- Data for Name: fees; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.fees VALUES ('60522f6f-00dc-430e-9e47-9a0bf5f65ea9', '1fd8c8e1-ce76-4b5c-b61d-a30376e9dd89', NULL, 'c2c625de-a6f0-4af2-9599-313ca60a8a47', 4900, 'EUR', 0, 0, '2025-07-01 00:00:01', '2025-06-12 11:00:00', 0.0, NULL, '{"to_datetime": "2025-07-01T00:00:00.000Z", "from_datetime": "2025-06-01T00:00:00.000Z", "charges_to_datetime": "2025-07-01T00:00:00.000Z", "charges_from_datetime": "2025-06-01T00:00:00.000Z"}', 2, 'Subscription', 'c2c625de-a6f0-4af2-9599-313ca60a8a47', NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, false, 0.00000, NULL, 'Herendil', 0.000000000000000, '{}', NULL, '{}', NULL, NULL, 4900.000000000000000, 0.000000000000000, 1, '1a4a0d6e-0000-4000-8000-0000000000a4', 'c305db8c-7259-4bc5-a087-2f55448d67d3', 0.00000, NULL, false, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO public.fees VALUES ('76e3ab12-6c5d-49f4-9608-5358e5b47f5e', '2114a5c7-6d22-4f09-ac8e-68da30f084c1', NULL, 'c2c625de-a6f0-4af2-9599-313ca60a8a47', 4900, 'EUR', 0, 0, '2025-08-01 00:00:01', '2025-06-12 11:00:00', 0.0, NULL, '{"to_datetime": "2025-08-01T00:00:00.000Z", "from_datetime": "2025-07-01T00:00:00.000Z", "charges_to_datetime": "2025-08-01T00:00:00.000Z", "charges_from_datetime": "2025-07-01T00:00:00.000Z"}', 2, 'Subscription', 'c2c625de-a6f0-4af2-9599-313ca60a8a47', NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, false, 0.00000, NULL, 'Hyarmendacil', 0.000000000000000, '{}', NULL, '{}', NULL, NULL, 4900.000000000000000, 0.000000000000000, 1, '1a4a0d6e-0000-4000-8000-0000000000a4', 'c305db8c-7259-4bc5-a087-2f55448d67d3', 0.00000, NULL, false, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO public.fees VALUES ('3f9f2893-59af-447f-9583-25f0cf6ccedd', '9d321c4e-9b57-4931-9a37-64599f0635a2', NULL, 'c2c625de-a6f0-4af2-9599-313ca60a8a47', 4900, 'EUR', 0, 0, '2025-09-01 00:00:01', '2025-06-12 11:00:00', 0.0, NULL, '{"to_datetime": "2025-09-01T00:00:00.000Z", "from_datetime": "2025-08-01T00:00:00.000Z", "charges_to_datetime": "2025-09-01T00:00:00.000Z", "charges_from_datetime": "2025-08-01T00:00:00.000Z"}', 2, 'Subscription', 'c2c625de-a6f0-4af2-9599-313ca60a8a47', NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, false, 0.00000, NULL, 'Folca', 0.000000000000000, '{}', NULL, '{}', NULL, NULL, 4900.000000000000000, 0.000000000000000, 1, '1a4a0d6e-0000-4000-8000-0000000000a4', 'c305db8c-7259-4bc5-a087-2f55448d67d3', 0.00000, NULL, false, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO public.fees VALUES ('e805bbed-4d79-43b2-b6aa-dafb1e031389', 'bc7ada20-afbd-4396-9472-677912eb16ba', NULL, '72d08146-3fb7-4acb-a36d-85a5a0285b8c', 120000, 'EUR', 0, 0, '2025-06-15 00:00:01', '2025-06-12 11:00:00', 0.0, NULL, '{"to_datetime": "2026-06-15T00:00:00.000Z", "from_datetime": "2025-06-15T00:00:00.000Z", "charges_to_datetime": "2026-06-15T00:00:00.000Z", "charges_from_datetime": "2025-06-15T00:00:00.000Z"}', 2, 'Subscription', '72d08146-3fb7-4acb-a36d-85a5a0285b8c', NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, false, 0.00000, NULL, 'Manthor', 0.000000000000000, '{}', NULL, '{}', NULL, NULL, 120000.000000000000000, 0.000000000000000, 1, '1a4a0d6e-0000-4000-8000-0000000000a4', 'c305db8c-7259-4bc5-a087-2f55448d67d3', 0.00000, NULL, false, NULL, NULL, NULL, NULL, NULL, NULL);


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

INSERT INTO public.api_keys VALUES ('73e2d10e-1a26-40d3-bfd6-ff923561b05b', '1a4a0d6e-0000-4000-8000-0000000000a4', '1a4a0d6e-0000-4000-8000-0000000000b4', '2025-06-12 11:00:00', '2025-06-12 11:00:00', NULL, NULL, 'MRR Capture Key', '{"fee": ["read", "write"], "tax": ["read", "write"], "plan": ["read", "write"], "x402": ["read", "write"], "alert": ["read", "write"], "event": ["read", "write"], "order": ["read", "write"], "quote": ["read", "write"], "add_on": ["read", "write"], "coupon": ["read", "write"], "wallet": ["read", "write"], "api_log": ["read", "write"], "feature": ["read", "write"], "invoice": ["read", "write"], "payment": ["read", "write"], "product": ["read", "write"], "analytic": ["read", "write"], "contract": ["read", "write"], "customer": ["read", "write"], "rate_card": ["read", "write"], "order_form": ["read", "write"], "credit_note": ["read", "write"], "activity_log": ["read", "write"], "organization": ["read", "write"], "security_log": ["read", "write"], "subscription": ["read", "write"], "applied_coupon": ["read", "write"], "billing_entity": ["read", "write"], "customer_usage": ["read", "write"], "lifetime_usage": ["read", "write"], "payment_method": ["read", "write"], "plan_rate_card": ["read", "write"], "billable_metric": ["read", "write"], "payment_receipt": ["read", "write"], "payment_request": ["read", "write"], "x402_connection": ["read", "write"], "product_category": ["read", "write"], "webhook_endpoint": ["read", "write"], "contract_rate_card": ["read", "write"], "wallet_transaction": ["read", "write"], "invoice_custom_section": ["read", "write"], "usage_attribution_type": ["read", "write"], "webhook_jwt_public_key": ["read", "write"]}');


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

INSERT INTO public.versions VALUES (1, 'Organization', '1a4a0d6e-0000-4000-8000-0000000000a4', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-0000000000a4"], "name": [null, "Contract MRR Org"], "slug": [null, "contract-mrr-org"], "email": [null, "alphonso.braun@schuster.test"], "hmac_key": [null, "ce8609b1-9249-4dbd-8135-4c380e7cff65"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "audit_logs_period": [30, null]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (2, 'BillingEntity', 'c305db8c-7259-4bc5-a087-2f55448d67d3', 'create', NULL, NULL, '{"id": [null, "c305db8c-7259-4bc5-a087-2f55448d67d3"], "code": [null, "entity_49eba90d-1d42-4e74-86ec-7864793195dd"], "name": [null, "Hintz, Connelly and Stanton"], "email": [null, "alec@rice.example"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a4"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (3, 'BillingEntity', 'c305db8c-7259-4bc5-a087-2f55448d67d3', 'update', NULL, '{"id": "c305db8c-7259-4bc5-a087-2f55448d67d3", "city": null, "code": "entity_49eba90d-1d42-4e74-86ec-7864793195dd", "logo": null, "name": "Hintz, Connelly and Stanton", "email": "alec@rice.example", "phone": null, "state": null, "country": null, "zipcode": null, "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-06-12T11:00:00.000Z", "deleted_at": null, "einvoicing": false, "legal_name": null, "updated_at": "2025-06-12T11:00:00.000Z", "archived_at": null, "legal_number": null, "payment_term": null, "address_line1": null, "address_line2": null, "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "organization_id": "1a4a0d6e-0000-4000-8000-0000000000a4", "default_currency": "USD", "net_payment_term": 0, "eu_tax_management": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "document_number_prefix": null, "tax_identification_number": null, "applied_dunning_campaign_id": null, "finalize_zero_amount_invoice": true, "subscription_invoice_issuing_date_anchor": "next_period_start", "subscription_invoice_issuing_date_adjustment": "align_with_finalization_date"}', '{"document_number_prefix": [null, "HIN-67D3"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (4, 'Organization', '1a4a0d6e-0000-4000-8000-0000000000a4', 'update', NULL, '{"id": "1a4a0d6e-0000-4000-8000-0000000000a4", "city": null, "logo": null, "name": "Contract MRR Org", "slug": "contract-mrr-org", "email": "alphonso.braun@schuster.test", "state": null, "api_key": null, "country": null, "zipcode": null, "hmac_key": "ce8609b1-9249-4dbd-8135-4c380e7cff65", "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-06-12T11:00:00.000Z", "legal_name": null, "updated_at": "2025-06-12T11:00:00.000Z", "max_wallets": null, "webhook_url": null, "legal_number": null, "address_line1": null, "address_line2": null, "feature_flags": [], "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "default_currency": "USD", "net_payment_term": 0, "audit_logs_period": null, "eu_tax_management": false, "custom_aggregation": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "premium_integrations": [], "authentication_methods": ["email_password", "google_oauth"], "document_number_prefix": null, "clickhouse_events_store": false, "tax_identification_number": null, "finalize_zero_amount_invoice": true, "clickhouse_deduplication_enabled": false}', '{"document_number_prefix": [null, "CON-00A4"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (5, 'ApiKey', '73e2d10e-1a26-40d3-bfd6-ff923561b05b', 'create', NULL, NULL, '{"id": [null, "73e2d10e-1a26-40d3-bfd6-ff923561b05b"], "name": [null, "MRR Capture Key"], "value": [null, "6e1b2d4a-6626-4321-b2c7-0556661443b1"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "permissions": [null, {"fee": ["read", "write"], "tax": ["read", "write"], "plan": ["read", "write"], "x402": ["read", "write"], "alert": ["read", "write"], "event": ["read", "write"], "order": ["read", "write"], "quote": ["read", "write"], "add_on": ["read", "write"], "coupon": ["read", "write"], "wallet": ["read", "write"], "api_log": ["read", "write"], "feature": ["read", "write"], "invoice": ["read", "write"], "payment": ["read", "write"], "product": ["read", "write"], "analytic": ["read", "write"], "contract": ["read", "write"], "customer": ["read", "write"], "rate_card": ["read", "write"], "order_form": ["read", "write"], "credit_note": ["read", "write"], "activity_log": ["read", "write"], "organization": ["read", "write"], "security_log": ["read", "write"], "subscription": ["read", "write"], "applied_coupon": ["read", "write"], "billing_entity": ["read", "write"], "customer_usage": ["read", "write"], "lifetime_usage": ["read", "write"], "payment_method": ["read", "write"], "plan_rate_card": ["read", "write"], "billable_metric": ["read", "write"], "payment_receipt": ["read", "write"], "payment_request": ["read", "write"], "x402_connection": ["read", "write"], "product_category": ["read", "write"], "webhook_endpoint": ["read", "write"], "contract_rate_card": ["read", "write"], "wallet_transaction": ["read", "write"], "invoice_custom_section": ["read", "write"], "usage_attribution_type": ["read", "write"], "webhook_jwt_public_key": ["read", "write"]}], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a4"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (6, 'Customer', 'e5659cf4-5f37-4422-ad18-4b1e9206da53', 'create', NULL, NULL, '{"id": [null, "e5659cf4-5f37-4422-ad18-4b1e9206da53"], "url": [null, "http://conroy-ferry.test/heriberto.moen"], "city": [null, "New Louis"], "name": [null, "MRR Customer"], "slug": [null, "CON-00A4-001"], "email": [null, "elisha.hayes@jones.test"], "phone": [null, "504.227.9282"], "state": [null, "Kentucky"], "country": [null, "CX"], "zipcode": [null, "86159-3732"], "currency": [null, "EUR"], "lastname": [null, "Hirthe"], "logo_url": [null, "http://kunze-emard.test/jerome.macejkovic"], "firstname": [null, "Denny"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "legal_name": [null, "Kilback-Grimes"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "external_id": [null, "mrr-cust-1"], "legal_number": [null, "99-600-4283"], "address_line1": [null, "96629 Nada Trail"], "address_line2": [null, "Apt. 186"], "sequential_id": [null, 1], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a4"], "billing_entity_id": [null, "c305db8c-7259-4bc5-a087-2f55448d67d3"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (7, 'Plan', '68c01647-66d0-4a41-a26e-23a9bf16dd01', 'create', NULL, NULL, '{"id": [null, "68c01647-66d0-4a41-a26e-23a9bf16dd01"], "code": [null, "mrr-monthly"], "name": [null, "MRR Monthly Plan"], "interval": [null, "monthly"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "description": [null, "Et vitae laborum qui."], "amount_cents": [null, 4900], "amount_currency": [null, "EUR"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a4"], "invoice_display_name": [null, "I See You"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (8, 'Plan', '08eda6fb-1728-4f4b-a597-9c3f3ac12f20', 'create', NULL, NULL, '{"id": [null, "08eda6fb-1728-4f4b-a597-9c3f3ac12f20"], "code": [null, "mrr-yearly"], "name": [null, "MRR Yearly Plan"], "interval": [null, "yearly"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "description": [null, "Atque et molestias natus."], "amount_cents": [null, 120000], "pay_in_advance": [false, true], "amount_currency": [null, "EUR"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a4"], "invoice_display_name": [null, "Thirty-Eight Snub"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (9, 'Subscription', 'c2c625de-a6f0-4af2-9599-313ca60a8a47', 'create', NULL, NULL, '{"id": [null, "c2c625de-a6f0-4af2-9599-313ca60a8a47"], "status": [null, "active"], "plan_id": [null, "68c01647-66d0-4a41-a26e-23a9bf16dd01"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "started_at": [null, "2025-06-11T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "e5659cf4-5f37-4422-ad18-4b1e9206da53"], "external_id": [null, "mrr-sub-monthly"], "activated_at": [null, "2025-06-11T11:00:00.000Z"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a4"], "subscription_at": [null, "2025-06-11T11:00:00.000Z"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (10, 'Subscription', '72d08146-3fb7-4acb-a36d-85a5a0285b8c', 'create', NULL, NULL, '{"id": [null, "72d08146-3fb7-4acb-a36d-85a5a0285b8c"], "status": [null, "active"], "plan_id": [null, "08eda6fb-1728-4f4b-a597-9c3f3ac12f20"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "started_at": [null, "2025-06-11T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "e5659cf4-5f37-4422-ad18-4b1e9206da53"], "external_id": [null, "mrr-sub-yearly"], "activated_at": [null, "2025-06-11T11:00:00.000Z"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a4"], "subscription_at": [null, "2025-06-11T11:00:00.000Z"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (11, 'Invoice', '1fd8c8e1-ce76-4b5c-b61d-a30376e9dd89', 'create', NULL, NULL, '{"id": [null, "1fd8c8e1-ce76-4b5c-b61d-a30376e9dd89"], "number": ["", "HIN-67D3-DRAFT"], "currency": [null, "EUR"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "e5659cf4-5f37-4422-ad18-4b1e9206da53"], "issuing_date": [null, "2025-07-01"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a4"], "payment_due_date": [null, "2025-07-01"], "billing_entity_id": [null, "c305db8c-7259-4bc5-a087-2f55448d67d3"], "total_amount_cents": [0, 4900], "expected_finalization_date": [null, "2025-06-11"], "organization_sequential_id": [0, 933358]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (12, 'Invoice', '2114a5c7-6d22-4f09-ac8e-68da30f084c1', 'create', NULL, NULL, '{"id": [null, "2114a5c7-6d22-4f09-ac8e-68da30f084c1"], "number": ["", "HIN-67D3-DRAFT"], "currency": [null, "EUR"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "e5659cf4-5f37-4422-ad18-4b1e9206da53"], "issuing_date": [null, "2025-08-01"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a4"], "payment_due_date": [null, "2025-08-01"], "billing_entity_id": [null, "c305db8c-7259-4bc5-a087-2f55448d67d3"], "total_amount_cents": [0, 4900], "expected_finalization_date": [null, "2025-06-11"], "organization_sequential_id": [0, 287916]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (13, 'Invoice', '9d321c4e-9b57-4931-9a37-64599f0635a2', 'create', NULL, NULL, '{"id": [null, "9d321c4e-9b57-4931-9a37-64599f0635a2"], "number": ["", "HIN-67D3-DRAFT"], "currency": [null, "EUR"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "e5659cf4-5f37-4422-ad18-4b1e9206da53"], "issuing_date": [null, "2025-09-01"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a4"], "payment_due_date": [null, "2025-09-01"], "billing_entity_id": [null, "c305db8c-7259-4bc5-a087-2f55448d67d3"], "total_amount_cents": [0, 4900], "expected_finalization_date": [null, "2025-06-11"], "organization_sequential_id": [0, 76078]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (14, 'Invoice', 'bc7ada20-afbd-4396-9472-677912eb16ba', 'create', NULL, NULL, '{"id": [null, "bc7ada20-afbd-4396-9472-677912eb16ba"], "number": ["", "HIN-67D3-DRAFT"], "currency": [null, "EUR"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "e5659cf4-5f37-4422-ad18-4b1e9206da53"], "issuing_date": [null, "2025-06-15"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a4"], "payment_due_date": [null, "2025-06-15"], "billing_entity_id": [null, "c305db8c-7259-4bc5-a087-2f55448d67d3"], "total_amount_cents": [0, 120000], "expected_finalization_date": [null, "2025-06-11"], "organization_sequential_id": [0, 270878]}', '2025-06-12 11:00:00', 'test');


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

SELECT pg_catalog.setval('public.versions_id_seq', 14, true);


--
-- PostgreSQL database dump complete
--


