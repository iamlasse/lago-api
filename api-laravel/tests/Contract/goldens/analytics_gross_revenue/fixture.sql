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

INSERT INTO public.organizations VALUES ('1a4a0d6e-0000-4000-8000-0000000000a1', 'Contract Gross Revenue Org', '2025-06-12 11:00:00', '2025-06-12 11:00:00', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'santos.cummings@turcotte.example', NULL, NULL, NULL, NULL, NULL, 0, 'UTC', 'en', '{invoice.finalized,credit_note.created}', NULL, 0, 'USD', 0, 'CON-00A1', false, '{}', false, true, false, '6d692536-3472-4e58-8c5e-baae05d100dd', '{email_password,google_oauth}', NULL, false, false, '{}', NULL, 'contract-gross-revenue-org');


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

INSERT INTO public.billing_entities VALUES ('b1d6336e-cd4a-4deb-bbbd-dee7a2167ac8', '1a4a0d6e-0000-4000-8000-0000000000a1', NULL, NULL, NULL, NULL, NULL, NULL, 'UTC', 'USD', 'en', 'RAT-7AC8', 'per_customer', true, NULL, 0, 0, 'colton@gislason.test', '{invoice.finalized,credit_note.created}', false, NULL, NULL, NULL, 'Ratke LLC', 'entity_5070044c-3592-446a-9be3-ec8143fb4ce1', NULL, 0, NULL, NULL, '2025-06-12 11:00:00', '2025-06-12 11:00:00', NULL, false, 'next_period_start', 'align_with_finalization_date', NULL, NULL);
INSERT INTO public.billing_entities VALUES ('e0a864cd-95b8-4ea3-a8a5-1db939c4d208', '1a4a0d6e-0000-4000-8000-0000000000a1', NULL, NULL, NULL, NULL, NULL, NULL, 'UTC', 'USD', 'en', 'CAP-D208', 'per_customer', true, NULL, 0, 0, 'bernadette@schumm.test', '{invoice.finalized,credit_note.created}', false, NULL, NULL, NULL, 'Capture Entity', 'capture-be', NULL, 0, NULL, NULL, '2025-06-12 11:00:00', '2025-06-12 11:00:00', NULL, false, 'next_period_start', 'align_with_finalization_date', NULL, NULL);


--
-- Data for Name: customers; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.customers VALUES ('71723d15-c809-46a2-b74f-3f65858ba577', 'grossrev-cust-1', 'Gross Revenue Customer One', '1a4a0d6e-0000-4000-8000-0000000000a1', '2025-06-12 11:00:00', '2025-06-12 11:00:00', 'EC', '182 Isiah Loaf', 'Suite 432', 'Missouri', '84987-8326', 'dianne.kirlin@hagenes.example', 'Gutmannchester', 'http://hackett.test/ezequiel', '587.429.5915', 'http://funk-cassin.example/emery', 'VonRueden-Hermiston', '33-593-6784', NULL, NULL, 'CON-00A1-001', 1, 'EUR', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'Gordon', 'Bernier', NULL, NULL, false, 0, NULL, false, 'customer', 'b1d6336e-cd4a-4deb-bbbd-dee7a2167ac8', 0, NULL, NULL, false, '{}', NULL);
INSERT INTO public.customers VALUES ('1f5c7194-5374-46d5-9e44-ddd81a05d5d8', 'grossrev-cust-2', 'Gross Revenue Customer Two', '1a4a0d6e-0000-4000-8000-0000000000a1', '2025-06-12 11:00:00', '2025-06-12 11:00:00', 'MZ', '303 Sung Parkways', 'Suite 162', 'New York', '71718', 'ron@wolff.example', 'Catricehaven', 'http://sawayn.example/miquel', '996.210.9844', 'http://lindgren.example/rachele', 'Champlin and Sons', '10-614-3216', NULL, NULL, 'CON-00A1-002', 2, 'EUR', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'Deana', 'McClure', NULL, NULL, false, 0, NULL, false, 'customer', 'b1d6336e-cd4a-4deb-bbbd-dee7a2167ac8', 0, NULL, NULL, false, '{}', NULL);


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

INSERT INTO public.plans VALUES ('dd4b528a-476a-4533-82f3-7365a4a5a59d', '1a4a0d6e-0000-4000-8000-0000000000a1', 'Gross Revenue Plan', '2025-06-12 11:00:00', '2025-06-12 11:00:00', 'grossrev-plan', 1, 'Quas quia natus consequuntur.', 4900, 'EUR', NULL, true, NULL, NULL, NULL, false, 'Cancer Man', false);


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

INSERT INTO public.invoices VALUES ('1a4a0d6e-0000-4000-8000-000000000101', '2025-06-12 11:00:00', '2025-06-12 11:00:00', '2025-06-05', 0, 10000, 0, 0, 'RAT-7AC8-DRAFT', NULL, NULL, '71723d15-c809-46a2-b74f-3f65858ba577', 0, 1, 'UTC', 0, true, '1a4a0d6e-0000-4000-8000-0000000000a1', 4, 'EUR', 0, 0, 0, 0, 0, 0, '2025-06-05', 0, NULL, 359086, false, NULL, false, false, 0, 0, NULL, 0, false, NULL, 'b1d6336e-cd4a-4deb-bbbd-dee7a2167ac8', NULL, NULL, NULL, NULL, '2025-06-11', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO public.invoices VALUES ('1a4a0d6e-0000-4000-8000-000000000102', '2025-06-12 11:00:00', '2025-06-12 11:00:00', '2025-06-20', 0, 5000, 0, 0, 'RAT-7AC8-DRAFT', NULL, NULL, '1f5c7194-5374-46d5-9e44-ddd81a05d5d8', 0, 1, 'UTC', 0, true, '1a4a0d6e-0000-4000-8000-0000000000a1', 4, 'EUR', 0, 0, 0, 0, 0, 0, '2025-06-20', 0, NULL, 513881, false, NULL, false, false, 0, 0, NULL, 0, false, NULL, 'b1d6336e-cd4a-4deb-bbbd-dee7a2167ac8', NULL, NULL, NULL, NULL, '2025-06-11', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO public.invoices VALUES ('1a4a0d6e-0000-4000-8000-000000000103', '2025-06-12 11:00:00', '2025-06-12 11:00:00', '2025-06-18', 0, 8000, 0, 0, 'CAP-D208-DRAFT', NULL, NULL, '71723d15-c809-46a2-b74f-3f65858ba577', 0, 1, 'UTC', 0, true, '1a4a0d6e-0000-4000-8000-0000000000a1', 4, 'EUR', 0, 0, 0, 0, 0, 0, '2025-06-18', 0, NULL, 247869, false, NULL, false, false, 0, 0, NULL, 0, false, NULL, 'e0a864cd-95b8-4ea3-a8a5-1db939c4d208', NULL, NULL, NULL, NULL, '2025-06-11', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO public.invoices VALUES ('1a4a0d6e-0000-4000-8000-000000000104', '2025-06-12 11:00:00', '2025-06-12 11:00:00', '2025-06-25', 0, 2000, 0, 0, 'RAT-7AC8-DRAFT', NULL, NULL, '1f5c7194-5374-46d5-9e44-ddd81a05d5d8', 0, 1, 'UTC', 0, true, '1a4a0d6e-0000-4000-8000-0000000000a1', 4, 'USD', 0, 0, 0, 0, 0, 0, '2025-06-25', 0, NULL, 455513, false, NULL, false, false, 0, 0, NULL, 0, false, NULL, 'b1d6336e-cd4a-4deb-bbbd-dee7a2167ac8', NULL, NULL, NULL, NULL, '2025-06-11', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO public.invoices VALUES ('1a4a0d6e-0000-4000-8000-000000000105', '2025-06-12 11:00:00', '2025-06-12 11:00:00', '2025-07-10', 0, 7000, 0, 0, 'RAT-7AC8-DRAFT', NULL, NULL, '71723d15-c809-46a2-b74f-3f65858ba577', 0, 1, 'UTC', 0, true, '1a4a0d6e-0000-4000-8000-0000000000a1', 4, 'EUR', 0, 0, 0, 0, 0, 0, '2025-07-10', 0, NULL, 25984, false, NULL, false, false, 0, 0, NULL, 0, false, NULL, 'b1d6336e-cd4a-4deb-bbbd-dee7a2167ac8', NULL, NULL, NULL, NULL, '2025-06-11', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO public.invoices VALUES ('1a4a0d6e-0000-4000-8000-000000000106', '2025-06-12 11:00:00', '2025-06-12 11:00:00', '2025-06-28', 0, 9999, 0, 0, 'RAT-7AC8-DRAFT', NULL, NULL, '71723d15-c809-46a2-b74f-3f65858ba577', 0, 0, 'UTC', 0, true, '1a4a0d6e-0000-4000-8000-0000000000a1', 4, 'EUR', 0, 0, 0, 0, 0, 0, '2025-06-28', 0, NULL, 308443, false, NULL, false, false, 0, 0, NULL, 0, false, NULL, 'b1d6336e-cd4a-4deb-bbbd-dee7a2167ac8', NULL, NULL, NULL, NULL, '2025-06-11', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO public.invoices VALUES ('1a4a0d6e-0000-4000-8000-000000000107', '2025-06-12 11:00:00', '2025-06-12 11:00:00', '2025-07-15', 0, 4242, 0, 0, 'RAT-7AC8-DRAFT', NULL, NULL, '71723d15-c809-46a2-b74f-3f65858ba577', 0, 1, 'UTC', 0, true, '1a4a0d6e-0000-4000-8000-0000000000a1', 4, 'EUR', 0, 0, 0, 0, 0, 0, '2025-07-15', 0, NULL, 18224, false, '2025-06-11 11:00:00', false, false, 0, 0, NULL, 0, false, NULL, 'b1d6336e-cd4a-4deb-bbbd-dee7a2167ac8', NULL, NULL, NULL, NULL, '2025-06-11', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);


--
-- Data for Name: rate_card_rates; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: rate_overrides; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: subscriptions; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.subscriptions VALUES ('c22c6157-cb40-4699-8799-615ede73ae30', '71723d15-c809-46a2-b74f-3f65858ba577', 'dd4b528a-476a-4533-82f3-7365a4a5a59d', 1, NULL, NULL, '2025-06-11 11:00:00', '2025-06-12 11:00:00', '2025-06-12 11:00:00', NULL, NULL, 'grossrev-sub-1', 0, '2025-06-11 11:00:00', NULL, NULL, '1a4a0d6e-0000-4000-8000-0000000000a1', NULL, 'generate', NULL, 'provider', false, false, NULL, NULL, NULL, '2025-06-11 11:00:00', NULL, true, false, NULL, NULL, NULL);


--
-- Data for Name: fees; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.fees VALUES ('384fa036-8c1c-4b3f-89d2-7d42856132cc', NULL, NULL, 'c22c6157-cb40-4699-8799-615ede73ae30', 1500, 'EUR', 0, 0, '2025-07-08 09:00:00', '2025-06-12 11:00:00', 0.0, NULL, '{}', 2, 'Subscription', 'c22c6157-cb40-4699-8799-615ede73ae30', NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, true, 0.00000, NULL, 'Théodred', 0.000000000000000, '{}', NULL, '{}', NULL, NULL, 200.000000000100000, 2.000000000100000, 1, '1a4a0d6e-0000-4000-8000-0000000000a1', 'b1d6336e-cd4a-4deb-bbbd-dee7a2167ac8', 0.00000, NULL, false, NULL, NULL, NULL, NULL, NULL, NULL);


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

INSERT INTO public.api_keys VALUES ('4a009244-0d4c-4cd1-b010-f798dbdc6c58', '1a4a0d6e-0000-4000-8000-0000000000a1', '1a4a0d6e-0000-4000-8000-0000000000b1', '2025-06-12 11:00:00', '2025-06-12 11:00:00', NULL, NULL, 'Gross Revenue Capture Key', '{"fee": ["read", "write"], "tax": ["read", "write"], "plan": ["read", "write"], "x402": ["read", "write"], "alert": ["read", "write"], "event": ["read", "write"], "order": ["read", "write"], "quote": ["read", "write"], "add_on": ["read", "write"], "coupon": ["read", "write"], "wallet": ["read", "write"], "api_log": ["read", "write"], "feature": ["read", "write"], "invoice": ["read", "write"], "payment": ["read", "write"], "product": ["read", "write"], "analytic": ["read", "write"], "contract": ["read", "write"], "customer": ["read", "write"], "rate_card": ["read", "write"], "order_form": ["read", "write"], "credit_note": ["read", "write"], "activity_log": ["read", "write"], "organization": ["read", "write"], "security_log": ["read", "write"], "subscription": ["read", "write"], "applied_coupon": ["read", "write"], "billing_entity": ["read", "write"], "customer_usage": ["read", "write"], "lifetime_usage": ["read", "write"], "payment_method": ["read", "write"], "plan_rate_card": ["read", "write"], "billable_metric": ["read", "write"], "payment_receipt": ["read", "write"], "payment_request": ["read", "write"], "x402_connection": ["read", "write"], "product_category": ["read", "write"], "webhook_endpoint": ["read", "write"], "contract_rate_card": ["read", "write"], "wallet_transaction": ["read", "write"], "invoice_custom_section": ["read", "write"], "usage_attribution_type": ["read", "write"], "webhook_jwt_public_key": ["read", "write"]}');


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

INSERT INTO public.credit_notes VALUES ('61a1e08f-d8e2-483c-9c85-0b6204d8b6d2', '71723d15-c809-46a2-b74f-3f65858ba577', '1a4a0d6e-0000-4000-8000-000000000101', 1, 'RAT-7AC8-DRAFT-CN001', 120, 'EUR', 0, 120, 'EUR', 0, NULL, '2025-06-12 11:00:00', '2025-06-12 11:00:00', 2000, 'EUR', 2000, 'EUR', NULL, NULL, NULL, 20, NULL, '2025-06-12', 1, 0, 0.00000, 0.00000, 0, '1a4a0d6e-0000-4000-8000-0000000000a1', NULL, 0, NULL);


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

INSERT INTO public.versions VALUES (1, 'Organization', '1a4a0d6e-0000-4000-8000-0000000000a1', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-0000000000a1"], "name": [null, "Contract Gross Revenue Org"], "slug": [null, "contract-gross-revenue-org"], "email": [null, "santos.cummings@turcotte.example"], "hmac_key": [null, "6d692536-3472-4e58-8c5e-baae05d100dd"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "audit_logs_period": [30, null]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (2, 'BillingEntity', 'b1d6336e-cd4a-4deb-bbbd-dee7a2167ac8', 'create', NULL, NULL, '{"id": [null, "b1d6336e-cd4a-4deb-bbbd-dee7a2167ac8"], "code": [null, "entity_5070044c-3592-446a-9be3-ec8143fb4ce1"], "name": [null, "Ratke LLC"], "email": [null, "colton@gislason.test"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a1"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (3, 'BillingEntity', 'b1d6336e-cd4a-4deb-bbbd-dee7a2167ac8', 'update', NULL, '{"id": "b1d6336e-cd4a-4deb-bbbd-dee7a2167ac8", "city": null, "code": "entity_5070044c-3592-446a-9be3-ec8143fb4ce1", "logo": null, "name": "Ratke LLC", "email": "colton@gislason.test", "phone": null, "state": null, "country": null, "zipcode": null, "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-06-12T11:00:00.000Z", "deleted_at": null, "einvoicing": false, "legal_name": null, "updated_at": "2025-06-12T11:00:00.000Z", "archived_at": null, "legal_number": null, "payment_term": null, "address_line1": null, "address_line2": null, "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "organization_id": "1a4a0d6e-0000-4000-8000-0000000000a1", "default_currency": "USD", "net_payment_term": 0, "eu_tax_management": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "document_number_prefix": null, "tax_identification_number": null, "applied_dunning_campaign_id": null, "finalize_zero_amount_invoice": true, "subscription_invoice_issuing_date_anchor": "next_period_start", "subscription_invoice_issuing_date_adjustment": "align_with_finalization_date"}', '{"document_number_prefix": [null, "RAT-7AC8"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (4, 'Organization', '1a4a0d6e-0000-4000-8000-0000000000a1', 'update', NULL, '{"id": "1a4a0d6e-0000-4000-8000-0000000000a1", "city": null, "logo": null, "name": "Contract Gross Revenue Org", "slug": "contract-gross-revenue-org", "email": "santos.cummings@turcotte.example", "state": null, "api_key": null, "country": null, "zipcode": null, "hmac_key": "6d692536-3472-4e58-8c5e-baae05d100dd", "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-06-12T11:00:00.000Z", "legal_name": null, "updated_at": "2025-06-12T11:00:00.000Z", "max_wallets": null, "webhook_url": null, "legal_number": null, "address_line1": null, "address_line2": null, "feature_flags": [], "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "default_currency": "USD", "net_payment_term": 0, "audit_logs_period": null, "eu_tax_management": false, "custom_aggregation": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "premium_integrations": [], "authentication_methods": ["email_password", "google_oauth"], "document_number_prefix": null, "clickhouse_events_store": false, "tax_identification_number": null, "finalize_zero_amount_invoice": true, "clickhouse_deduplication_enabled": false}', '{"document_number_prefix": [null, "CON-00A1"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (5, 'ApiKey', '4a009244-0d4c-4cd1-b010-f798dbdc6c58', 'create', NULL, NULL, '{"id": [null, "4a009244-0d4c-4cd1-b010-f798dbdc6c58"], "name": [null, "Gross Revenue Capture Key"], "value": [null, "8a95ac27-766c-4b7d-bff6-bf6b1bb0f681"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "permissions": [null, {"fee": ["read", "write"], "tax": ["read", "write"], "plan": ["read", "write"], "x402": ["read", "write"], "alert": ["read", "write"], "event": ["read", "write"], "order": ["read", "write"], "quote": ["read", "write"], "add_on": ["read", "write"], "coupon": ["read", "write"], "wallet": ["read", "write"], "api_log": ["read", "write"], "feature": ["read", "write"], "invoice": ["read", "write"], "payment": ["read", "write"], "product": ["read", "write"], "analytic": ["read", "write"], "contract": ["read", "write"], "customer": ["read", "write"], "rate_card": ["read", "write"], "order_form": ["read", "write"], "credit_note": ["read", "write"], "activity_log": ["read", "write"], "organization": ["read", "write"], "security_log": ["read", "write"], "subscription": ["read", "write"], "applied_coupon": ["read", "write"], "billing_entity": ["read", "write"], "customer_usage": ["read", "write"], "lifetime_usage": ["read", "write"], "payment_method": ["read", "write"], "plan_rate_card": ["read", "write"], "billable_metric": ["read", "write"], "payment_receipt": ["read", "write"], "payment_request": ["read", "write"], "x402_connection": ["read", "write"], "product_category": ["read", "write"], "webhook_endpoint": ["read", "write"], "contract_rate_card": ["read", "write"], "wallet_transaction": ["read", "write"], "invoice_custom_section": ["read", "write"], "usage_attribution_type": ["read", "write"], "webhook_jwt_public_key": ["read", "write"]}], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a1"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (6, 'Customer', '71723d15-c809-46a2-b74f-3f65858ba577', 'create', NULL, NULL, '{"id": [null, "71723d15-c809-46a2-b74f-3f65858ba577"], "url": [null, "http://hackett.test/ezequiel"], "city": [null, "Gutmannchester"], "name": [null, "Gross Revenue Customer One"], "slug": [null, "CON-00A1-001"], "email": [null, "dianne.kirlin@hagenes.example"], "phone": [null, "587.429.5915"], "state": [null, "Missouri"], "country": [null, "EC"], "zipcode": [null, "84987-8326"], "currency": [null, "EUR"], "lastname": [null, "Bernier"], "logo_url": [null, "http://funk-cassin.example/emery"], "firstname": [null, "Gordon"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "legal_name": [null, "VonRueden-Hermiston"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "external_id": [null, "grossrev-cust-1"], "legal_number": [null, "33-593-6784"], "address_line1": [null, "182 Isiah Loaf"], "address_line2": [null, "Suite 432"], "sequential_id": [null, 1], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a1"], "billing_entity_id": [null, "b1d6336e-cd4a-4deb-bbbd-dee7a2167ac8"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (7, 'Customer', '1f5c7194-5374-46d5-9e44-ddd81a05d5d8', 'create', NULL, NULL, '{"id": [null, "1f5c7194-5374-46d5-9e44-ddd81a05d5d8"], "url": [null, "http://sawayn.example/miquel"], "city": [null, "Catricehaven"], "name": [null, "Gross Revenue Customer Two"], "slug": [null, "CON-00A1-002"], "email": [null, "ron@wolff.example"], "phone": [null, "996.210.9844"], "state": [null, "New York"], "country": [null, "MZ"], "zipcode": [null, "71718"], "currency": [null, "EUR"], "lastname": [null, "McClure"], "logo_url": [null, "http://lindgren.example/rachele"], "firstname": [null, "Deana"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "legal_name": [null, "Champlin and Sons"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "external_id": [null, "grossrev-cust-2"], "legal_number": [null, "10-614-3216"], "address_line1": [null, "303 Sung Parkways"], "address_line2": [null, "Suite 162"], "sequential_id": [null, 2], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a1"], "billing_entity_id": [null, "b1d6336e-cd4a-4deb-bbbd-dee7a2167ac8"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (8, 'BillingEntity', 'e0a864cd-95b8-4ea3-a8a5-1db939c4d208', 'create', NULL, NULL, '{"id": [null, "e0a864cd-95b8-4ea3-a8a5-1db939c4d208"], "code": [null, "capture-be"], "name": [null, "Capture Entity"], "email": [null, "bernadette@schumm.test"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a1"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (9, 'BillingEntity', 'e0a864cd-95b8-4ea3-a8a5-1db939c4d208', 'update', NULL, '{"id": "e0a864cd-95b8-4ea3-a8a5-1db939c4d208", "city": null, "code": "capture-be", "logo": null, "name": "Capture Entity", "email": "bernadette@schumm.test", "phone": null, "state": null, "country": null, "zipcode": null, "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-06-12T11:00:00.000Z", "deleted_at": null, "einvoicing": false, "legal_name": null, "updated_at": "2025-06-12T11:00:00.000Z", "archived_at": null, "legal_number": null, "payment_term": null, "address_line1": null, "address_line2": null, "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "organization_id": "1a4a0d6e-0000-4000-8000-0000000000a1", "default_currency": "USD", "net_payment_term": 0, "eu_tax_management": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "document_number_prefix": null, "tax_identification_number": null, "applied_dunning_campaign_id": null, "finalize_zero_amount_invoice": true, "subscription_invoice_issuing_date_anchor": "next_period_start", "subscription_invoice_issuing_date_adjustment": "align_with_finalization_date"}', '{"document_number_prefix": [null, "CAP-D208"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (10, 'Plan', 'dd4b528a-476a-4533-82f3-7365a4a5a59d', 'create', NULL, NULL, '{"id": [null, "dd4b528a-476a-4533-82f3-7365a4a5a59d"], "code": [null, "grossrev-plan"], "name": [null, "Gross Revenue Plan"], "interval": [null, "monthly"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "description": [null, "Quas quia natus consequuntur."], "amount_cents": [null, 4900], "pay_in_advance": [false, true], "amount_currency": [null, "EUR"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a1"], "invoice_display_name": [null, "Cancer Man"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (11, 'Subscription', 'c22c6157-cb40-4699-8799-615ede73ae30', 'create', NULL, NULL, '{"id": [null, "c22c6157-cb40-4699-8799-615ede73ae30"], "status": [null, "active"], "plan_id": [null, "dd4b528a-476a-4533-82f3-7365a4a5a59d"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "started_at": [null, "2025-06-11T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "71723d15-c809-46a2-b74f-3f65858ba577"], "external_id": [null, "grossrev-sub-1"], "activated_at": [null, "2025-06-11T11:00:00.000Z"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a1"], "subscription_at": [null, "2025-06-11T11:00:00.000Z"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (12, 'Invoice', '1a4a0d6e-0000-4000-8000-000000000101', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000101"], "number": ["", "RAT-7AC8-DRAFT"], "currency": [null, "EUR"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "71723d15-c809-46a2-b74f-3f65858ba577"], "issuing_date": [null, "2025-06-05"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a1"], "payment_due_date": [null, "2025-06-05"], "billing_entity_id": [null, "b1d6336e-cd4a-4deb-bbbd-dee7a2167ac8"], "total_amount_cents": [0, 10000], "expected_finalization_date": [null, "2025-06-11"], "organization_sequential_id": [0, 359086]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (13, 'Invoice', '1a4a0d6e-0000-4000-8000-000000000102', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000102"], "number": ["", "RAT-7AC8-DRAFT"], "currency": [null, "EUR"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "1f5c7194-5374-46d5-9e44-ddd81a05d5d8"], "issuing_date": [null, "2025-06-20"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a1"], "payment_due_date": [null, "2025-06-20"], "billing_entity_id": [null, "b1d6336e-cd4a-4deb-bbbd-dee7a2167ac8"], "total_amount_cents": [0, 5000], "expected_finalization_date": [null, "2025-06-11"], "organization_sequential_id": [0, 513881]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (14, 'CreditNote', '61a1e08f-d8e2-483c-9c85-0b6204d8b6d2', 'create', NULL, NULL, '{"id": [null, "61a1e08f-d8e2-483c-9c85-0b6204d8b6d2"], "number": [null, "RAT-7AC8-DRAFT-CN001"], "reason": [null, "duplicated_charge"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "invoice_id": [null, "1a4a0d6e-0000-4000-8000-000000000101"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "71723d15-c809-46a2-b74f-3f65858ba577"], "issuing_date": [null, "2025-06-12"], "credit_status": [null, "available"], "sequential_id": [null, 1], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a1"], "taxes_amount_cents": [0, 20], "total_amount_cents": [0, 2000], "credit_amount_cents": [0, 120], "refund_amount_cents": [0, 2000], "balance_amount_cents": [0, 120], "total_amount_currency": [null, "EUR"], "credit_amount_currency": [null, "EUR"], "refund_amount_currency": [null, "EUR"], "balance_amount_currency": ["0", "EUR"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (15, 'Invoice', '1a4a0d6e-0000-4000-8000-000000000103', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000103"], "number": ["", "CAP-D208-DRAFT"], "currency": [null, "EUR"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "71723d15-c809-46a2-b74f-3f65858ba577"], "issuing_date": [null, "2025-06-18"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a1"], "payment_due_date": [null, "2025-06-18"], "billing_entity_id": [null, "e0a864cd-95b8-4ea3-a8a5-1db939c4d208"], "total_amount_cents": [0, 8000], "expected_finalization_date": [null, "2025-06-11"], "organization_sequential_id": [0, 247869]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (16, 'Invoice', '1a4a0d6e-0000-4000-8000-000000000104', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000104"], "number": ["", "RAT-7AC8-DRAFT"], "currency": [null, "USD"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "1f5c7194-5374-46d5-9e44-ddd81a05d5d8"], "issuing_date": [null, "2025-06-25"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a1"], "payment_due_date": [null, "2025-06-25"], "billing_entity_id": [null, "b1d6336e-cd4a-4deb-bbbd-dee7a2167ac8"], "total_amount_cents": [0, 2000], "expected_finalization_date": [null, "2025-06-11"], "organization_sequential_id": [0, 455513]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (17, 'Invoice', '1a4a0d6e-0000-4000-8000-000000000105', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000105"], "number": ["", "RAT-7AC8-DRAFT"], "currency": [null, "EUR"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "71723d15-c809-46a2-b74f-3f65858ba577"], "issuing_date": [null, "2025-07-10"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a1"], "payment_due_date": [null, "2025-07-10"], "billing_entity_id": [null, "b1d6336e-cd4a-4deb-bbbd-dee7a2167ac8"], "total_amount_cents": [0, 7000], "expected_finalization_date": [null, "2025-06-11"], "organization_sequential_id": [0, 25984]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (18, 'Invoice', '1a4a0d6e-0000-4000-8000-000000000106', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000106"], "number": ["", "RAT-7AC8-DRAFT"], "status": ["finalized", "draft"], "currency": [null, "EUR"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "71723d15-c809-46a2-b74f-3f65858ba577"], "issuing_date": [null, "2025-06-28"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a1"], "payment_due_date": [null, "2025-06-28"], "billing_entity_id": [null, "b1d6336e-cd4a-4deb-bbbd-dee7a2167ac8"], "total_amount_cents": [0, 9999], "expected_finalization_date": [null, "2025-06-11"], "organization_sequential_id": [0, 308443]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (19, 'Invoice', '1a4a0d6e-0000-4000-8000-000000000107', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000107"], "number": ["", "RAT-7AC8-DRAFT"], "currency": [null, "EUR"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "71723d15-c809-46a2-b74f-3f65858ba577"], "issuing_date": [null, "2025-07-15"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a1"], "payment_due_date": [null, "2025-07-15"], "billing_entity_id": [null, "b1d6336e-cd4a-4deb-bbbd-dee7a2167ac8"], "total_amount_cents": [0, 4242], "payment_dispute_lost_at": [null, "2025-06-11T11:00:00.000Z"], "expected_finalization_date": [null, "2025-06-11"], "organization_sequential_id": [0, 18224]}', '2025-06-12 11:00:00', 'test');


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

SELECT pg_catalog.setval('public.versions_id_seq', 19, true);


--
-- PostgreSQL database dump complete
--


