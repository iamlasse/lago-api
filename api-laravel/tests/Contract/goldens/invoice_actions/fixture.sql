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

INSERT INTO public.organizations VALUES ('1a4a0d6e-0000-4000-8000-000000000081', 'Contract Invoice Actions Org', '2025-06-12 13:00:00', '2025-06-12 13:00:00', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'sindy.schmeler@willms.example', NULL, NULL, NULL, NULL, NULL, 0, 'UTC', 'en', '{invoice.finalized,credit_note.created}', NULL, 0, 'USD', 0, 'CON-0081', false, '{}', false, true, false, '7d236b45-8d6b-4484-949f-5f4b01933163', '{email_password,google_oauth}', NULL, false, false, '{}', NULL, 'contract-invoice-actions-org');


--
-- Data for Name: add_ons; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.add_ons VALUES ('1a4a0d6e-0000-4000-8000-000000000082', '1a4a0d6e-0000-4000-8000-000000000081', 'Actions Setup Add-on', 'setup-fee', 'seeded add-on', 1500, 'EUR', '2025-06-12 13:00:00', '2025-06-12 13:00:00', NULL, 'Setup fee');


--
-- Data for Name: taxes; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.taxes VALUES ('c0bb6c6e-7883-4203-a7b6-54b6637b83a8', '1a4a0d6e-0000-4000-8000-000000000081', 'French Standard VAT', 'actions-vat-20', 'Actions VAT', 20, '2025-06-12 13:00:00', '2025-06-12 13:00:00', false, false, NULL);


--
-- Data for Name: add_ons_taxes; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: dunning_campaigns; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: billing_entities; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.billing_entities VALUES ('27ecbeb2-2af7-4590-9cb4-847e42f26cda', '1a4a0d6e-0000-4000-8000-000000000081', NULL, NULL, NULL, NULL, NULL, NULL, 'UTC', 'USD', 'en', 'LAR-6CDA', 'per_customer', true, NULL, 0, 0, 'frances@johnson-boyle.test', '{invoice.finalized,credit_note.created}', false, NULL, NULL, NULL, 'Larson, Schmitt and Romaguera', 'entity_ac0c5b6c-b1cb-434c-8d8f-768422d9f7ea', NULL, 0, NULL, NULL, '2025-06-12 13:00:00', '2025-06-12 13:00:00', NULL, false, 'next_period_start', 'align_with_finalization_date', NULL, NULL);


--
-- Data for Name: customers; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.customers VALUES ('842b20fc-19ce-444a-8ff3-b4f07288d29f', 'invoice-actions-customer', 'Actions Customer Co.', '1a4a0d6e-0000-4000-8000-000000000081', '2025-06-12 13:00:00', '2025-06-12 13:00:00', 'FR', '11 Rue des Actions', 'Apt. 433', 'IDF', '75002', 'actions-customer@example.invalid', 'Paris', 'http://kemmer-schuster.example/stephan', '587-966-4085', 'http://bergnaum.example/allen', 'Rohan and Sons', '02-066-7686', NULL, NULL, 'CON-0081-001', 1, 'EUR', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'Dorethea', 'Schowalter', NULL, NULL, false, 0, NULL, false, 'customer', '27ecbeb2-2af7-4590-9cb4-847e42f26cda', 0, NULL, NULL, false, '{}', NULL);


--
-- Data for Name: applied_add_ons; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: billable_metrics; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.billable_metrics VALUES ('1a4a0d6e-0000-4000-8000-000000000083', '1a4a0d6e-0000-4000-8000-000000000081', 'Actions API Calls', 'api_calls', 'seeded sum metric', '{}', 1, '2025-06-12 13:00:00', '2025-06-12 13:00:00', 'calls', NULL, false, NULL, NULL, '', NULL, NULL);


--
-- Data for Name: catalog_plans; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: plans; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.plans VALUES ('359c110d-ac3c-4d2e-87c5-b81fcbf0b562', '1a4a0d6e-0000-4000-8000-000000000081', 'Actions Plan', '2025-06-12 13:00:00', '2025-06-12 13:00:00', 'actions-plan', 1, 'plan for the draft invoice', 4900, 'EUR', NULL, false, NULL, NULL, NULL, false, 'Actions Plan Display', false);


--
-- Data for Name: charges; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.charges VALUES ('e64dd4b3-b1c7-4891-b8b9-4033c6f538d7', '1a4a0d6e-0000-4000-8000-000000000083', '2025-06-12 13:00:00', '2025-06-12 13:00:00', '359c110d-ac3c-4d2e-87c5-b81fcbf0b562', NULL, 0, '{"amount": "0.10"}', NULL, false, 0, true, false, 'API calls', NULL, NULL, '1a4a0d6e-0000-4000-8000-000000000081', 'api-calls-charge', false);


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

INSERT INTO public.invoices VALUES ('1a4a0d6e-0000-4000-8000-000000000084', '2025-06-12 13:00:00', '2025-06-12 13:00:00', '2025-06-11', 0, 0, 0, 0, 'LAR-6CDA-DRAFT', NULL, NULL, '842b20fc-19ce-444a-8ff3-b4f07288d29f', 0, 0, 'UTC', 0, true, '1a4a0d6e-0000-4000-8000-000000000081', 4, 'EUR', 0, 0, 0, 0, 0, 0, '2025-06-11', 0, NULL, 189108, false, NULL, false, false, 0, 0, NULL, 0, false, NULL, '27ecbeb2-2af7-4590-9cb4-847e42f26cda', NULL, NULL, NULL, NULL, '2025-06-11', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);


--
-- Data for Name: rate_card_rates; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: rate_overrides; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: subscriptions; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.subscriptions VALUES ('9b3f2e2e-3ae0-44e4-b5ea-a08c2446909a', '842b20fc-19ce-444a-8ff3-b4f07288d29f', '359c110d-ac3c-4d2e-87c5-b81fcbf0b562', 1, NULL, NULL, '2025-06-01 00:00:00', '2025-06-12 13:00:00', '2025-06-12 13:00:00', NULL, NULL, 'invoice-actions-sub-1', 0, '2025-06-01 00:00:00', NULL, NULL, '1a4a0d6e-0000-4000-8000-000000000081', NULL, 'generate', NULL, 'provider', false, false, NULL, NULL, NULL, '2025-06-01 00:00:00', NULL, true, false, NULL, NULL, NULL);


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

INSERT INTO public.api_keys VALUES ('13d17ee5-10fc-4e1e-a4d2-843cff983546', '1a4a0d6e-0000-4000-8000-000000000081', '1a4a0d6e-0000-4000-8000-0000000000ad', '2025-06-12 13:00:00', '2025-06-12 13:00:00', NULL, NULL, 'Invoice Actions Capture Key', '{"fee": ["read", "write"], "tax": ["read", "write"], "plan": ["read", "write"], "x402": ["read", "write"], "alert": ["read", "write"], "event": ["read", "write"], "order": ["read", "write"], "quote": ["read", "write"], "add_on": ["read", "write"], "coupon": ["read", "write"], "wallet": ["read", "write"], "api_log": ["read", "write"], "feature": ["read", "write"], "invoice": ["read", "write"], "payment": ["read", "write"], "product": ["read", "write"], "analytic": ["read", "write"], "contract": ["read", "write"], "customer": ["read", "write"], "rate_card": ["read", "write"], "order_form": ["read", "write"], "credit_note": ["read", "write"], "activity_log": ["read", "write"], "organization": ["read", "write"], "security_log": ["read", "write"], "subscription": ["read", "write"], "applied_coupon": ["read", "write"], "billing_entity": ["read", "write"], "customer_usage": ["read", "write"], "lifetime_usage": ["read", "write"], "payment_method": ["read", "write"], "plan_rate_card": ["read", "write"], "billable_metric": ["read", "write"], "payment_receipt": ["read", "write"], "payment_request": ["read", "write"], "x402_connection": ["read", "write"], "product_category": ["read", "write"], "webhook_endpoint": ["read", "write"], "contract_rate_card": ["read", "write"], "wallet_transaction": ["read", "write"], "invoice_custom_section": ["read", "write"], "usage_attribution_type": ["read", "write"], "webhook_jwt_public_key": ["read", "write"]}');


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

INSERT INTO public.customers_taxes VALUES ('530478b2-2af6-4f1a-ae1b-7f2b76eac206', '842b20fc-19ce-444a-8ff3-b4f07288d29f', 'c0bb6c6e-7883-4203-a7b6-54b6637b83a8', '2025-06-12 13:00:00', '2025-06-12 13:00:00', '1a4a0d6e-0000-4000-8000-000000000081');


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

INSERT INTO public.invoice_subscriptions VALUES ('c78d7b66-cd43-444a-969a-26c5406be78d', '1a4a0d6e-0000-4000-8000-000000000084', '9b3f2e2e-3ae0-44e4-b5ea-a08c2446909a', '2025-06-12 13:00:00', '2025-06-12 13:00:00', false, '2025-06-12 13:00:00', '2025-06-01 00:00:00', '2025-06-30 23:59:59.999999', '2025-05-01 00:00:00', '2025-06-30 23:59:59.999999', NULL, '1a4a0d6e-0000-4000-8000-000000000081', NULL, '2025-06-01 00:00:00', '2025-06-30 23:59:59.999999');


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

INSERT INTO public.versions VALUES (1, 'Organization', '1a4a0d6e-0000-4000-8000-000000000081', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000081"], "name": [null, "Contract Invoice Actions Org"], "slug": [null, "contract-invoice-actions-org"], "email": [null, "sindy.schmeler@willms.example"], "hmac_key": [null, "7d236b45-8d6b-4484-949f-5f4b01933163"], "created_at": [null, "2025-06-12T13:00:00.000Z"], "updated_at": [null, "2025-06-12T13:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "audit_logs_period": [30, null]}', '2025-06-12 13:00:00', 'test');
INSERT INTO public.versions VALUES (2, 'BillingEntity', '27ecbeb2-2af7-4590-9cb4-847e42f26cda', 'create', NULL, NULL, '{"id": [null, "27ecbeb2-2af7-4590-9cb4-847e42f26cda"], "code": [null, "entity_ac0c5b6c-b1cb-434c-8d8f-768422d9f7ea"], "name": [null, "Larson, Schmitt and Romaguera"], "email": [null, "frances@johnson-boyle.test"], "created_at": [null, "2025-06-12T13:00:00.000Z"], "updated_at": [null, "2025-06-12T13:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000081"]}', '2025-06-12 13:00:00', 'test');
INSERT INTO public.versions VALUES (3, 'BillingEntity', '27ecbeb2-2af7-4590-9cb4-847e42f26cda', 'update', NULL, '{"id": "27ecbeb2-2af7-4590-9cb4-847e42f26cda", "city": null, "code": "entity_ac0c5b6c-b1cb-434c-8d8f-768422d9f7ea", "logo": null, "name": "Larson, Schmitt and Romaguera", "email": "frances@johnson-boyle.test", "phone": null, "state": null, "country": null, "zipcode": null, "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-06-12T13:00:00.000Z", "deleted_at": null, "einvoicing": false, "legal_name": null, "updated_at": "2025-06-12T13:00:00.000Z", "archived_at": null, "legal_number": null, "payment_term": null, "address_line1": null, "address_line2": null, "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "organization_id": "1a4a0d6e-0000-4000-8000-000000000081", "default_currency": "USD", "net_payment_term": 0, "eu_tax_management": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "document_number_prefix": null, "tax_identification_number": null, "applied_dunning_campaign_id": null, "finalize_zero_amount_invoice": true, "subscription_invoice_issuing_date_anchor": "next_period_start", "subscription_invoice_issuing_date_adjustment": "align_with_finalization_date"}', '{"document_number_prefix": [null, "LAR-6CDA"]}', '2025-06-12 13:00:00', 'test');
INSERT INTO public.versions VALUES (4, 'Organization', '1a4a0d6e-0000-4000-8000-000000000081', 'update', NULL, '{"id": "1a4a0d6e-0000-4000-8000-000000000081", "city": null, "logo": null, "name": "Contract Invoice Actions Org", "slug": "contract-invoice-actions-org", "email": "sindy.schmeler@willms.example", "state": null, "api_key": null, "country": null, "zipcode": null, "hmac_key": "7d236b45-8d6b-4484-949f-5f4b01933163", "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-06-12T13:00:00.000Z", "legal_name": null, "updated_at": "2025-06-12T13:00:00.000Z", "max_wallets": null, "webhook_url": null, "legal_number": null, "address_line1": null, "address_line2": null, "feature_flags": [], "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "default_currency": "USD", "net_payment_term": 0, "audit_logs_period": null, "eu_tax_management": false, "custom_aggregation": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "premium_integrations": [], "authentication_methods": ["email_password", "google_oauth"], "document_number_prefix": null, "clickhouse_events_store": false, "tax_identification_number": null, "finalize_zero_amount_invoice": true, "clickhouse_deduplication_enabled": false}', '{"document_number_prefix": [null, "CON-0081"]}', '2025-06-12 13:00:00', 'test');
INSERT INTO public.versions VALUES (5, 'ApiKey', '13d17ee5-10fc-4e1e-a4d2-843cff983546', 'create', NULL, NULL, '{"id": [null, "13d17ee5-10fc-4e1e-a4d2-843cff983546"], "name": [null, "Invoice Actions Capture Key"], "value": [null, "4a5ece80-de60-458e-8f0e-6b943d00b8d4"], "created_at": [null, "2025-06-12T13:00:00.000Z"], "updated_at": [null, "2025-06-12T13:00:00.000Z"], "permissions": [null, {"fee": ["read", "write"], "tax": ["read", "write"], "plan": ["read", "write"], "x402": ["read", "write"], "alert": ["read", "write"], "event": ["read", "write"], "order": ["read", "write"], "quote": ["read", "write"], "add_on": ["read", "write"], "coupon": ["read", "write"], "wallet": ["read", "write"], "api_log": ["read", "write"], "feature": ["read", "write"], "invoice": ["read", "write"], "payment": ["read", "write"], "product": ["read", "write"], "analytic": ["read", "write"], "contract": ["read", "write"], "customer": ["read", "write"], "rate_card": ["read", "write"], "order_form": ["read", "write"], "credit_note": ["read", "write"], "activity_log": ["read", "write"], "organization": ["read", "write"], "security_log": ["read", "write"], "subscription": ["read", "write"], "applied_coupon": ["read", "write"], "billing_entity": ["read", "write"], "customer_usage": ["read", "write"], "lifetime_usage": ["read", "write"], "payment_method": ["read", "write"], "plan_rate_card": ["read", "write"], "billable_metric": ["read", "write"], "payment_receipt": ["read", "write"], "payment_request": ["read", "write"], "x402_connection": ["read", "write"], "product_category": ["read", "write"], "webhook_endpoint": ["read", "write"], "contract_rate_card": ["read", "write"], "wallet_transaction": ["read", "write"], "invoice_custom_section": ["read", "write"], "usage_attribution_type": ["read", "write"], "webhook_jwt_public_key": ["read", "write"]}], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000081"]}', '2025-06-12 13:00:00', 'test');
INSERT INTO public.versions VALUES (6, 'AddOn', '1a4a0d6e-0000-4000-8000-000000000082', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000082"], "code": [null, "setup-fee"], "name": [null, "Actions Setup Add-on"], "created_at": [null, "2025-06-12T13:00:00.000Z"], "updated_at": [null, "2025-06-12T13:00:00.000Z"], "description": [null, "seeded add-on"], "amount_cents": [null, 1500], "amount_currency": [null, "EUR"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000081"], "invoice_display_name": [null, "Setup fee"]}', '2025-06-12 13:00:00', 'test');
INSERT INTO public.versions VALUES (7, 'BillableMetric', '1a4a0d6e-0000-4000-8000-000000000083', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000083"], "code": [null, "api_calls"], "name": [null, "Actions API Calls"], "created_at": [null, "2025-06-12T13:00:00.000Z"], "expression": [null, ""], "field_name": [null, "calls"], "updated_at": [null, "2025-06-12T13:00:00.000Z"], "description": [null, "seeded sum metric"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000081"], "aggregation_type": [null, "sum_agg"]}', '2025-06-12 13:00:00', 'test');
INSERT INTO public.versions VALUES (8, 'Plan', '359c110d-ac3c-4d2e-87c5-b81fcbf0b562', 'create', NULL, NULL, '{"id": [null, "359c110d-ac3c-4d2e-87c5-b81fcbf0b562"], "code": [null, "actions-plan"], "name": [null, "Actions Plan"], "interval": [null, "monthly"], "created_at": [null, "2025-06-12T13:00:00.000Z"], "updated_at": [null, "2025-06-12T13:00:00.000Z"], "description": [null, "plan for the draft invoice"], "amount_cents": [null, 4900], "amount_currency": [null, "EUR"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000081"], "invoice_display_name": [null, "Actions Plan Display"]}', '2025-06-12 13:00:00', 'test');
INSERT INTO public.versions VALUES (9, 'Charge', 'e64dd4b3-b1c7-4891-b8b9-4033c6f538d7', 'create', NULL, NULL, '{"id": [null, "e64dd4b3-b1c7-4891-b8b9-4033c6f538d7"], "code": [null, "api-calls-charge"], "plan_id": [null, "359c110d-ac3c-4d2e-87c5-b81fcbf0b562"], "created_at": [null, "2025-06-12T13:00:00.000Z"], "properties": ["{}", {"amount": "0.10"}], "updated_at": [null, "2025-06-12T13:00:00.000Z"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000081"], "billable_metric_id": [null, "1a4a0d6e-0000-4000-8000-000000000083"], "invoice_display_name": [null, "API calls"]}', '2025-06-12 13:00:00', 'test');
INSERT INTO public.versions VALUES (10, 'Plan', '359c110d-ac3c-4d2e-87c5-b81fcbf0b562', 'update', NULL, '{"id": "359c110d-ac3c-4d2e-87c5-b81fcbf0b562", "code": "actions-plan", "name": "Actions Plan", "interval": "monthly", "parent_id": null, "created_at": "2025-06-12T13:00:00.000Z", "deleted_at": null, "updated_at": "2025-06-12T13:00:00.000Z", "description": "plan for the draft invoice", "amount_cents": 4900, "trial_period": null, "pay_in_advance": false, "amount_currency": "EUR", "organization_id": "1a4a0d6e-0000-4000-8000-000000000081", "pending_deletion": false, "bill_charges_monthly": null, "invoice_display_name": "Actions Plan Display", "bill_fixed_charges_monthly": false}', NULL, '2025-06-12 13:00:00', 'test');
INSERT INTO public.versions VALUES (11, 'Tax', 'c0bb6c6e-7883-4203-a7b6-54b6637b83a8', 'create', NULL, NULL, '{"id": [null, "c0bb6c6e-7883-4203-a7b6-54b6637b83a8"], "code": [null, "actions-vat-20"], "name": [null, "Actions VAT"], "rate": [0.0, 20.0], "created_at": [null, "2025-06-12T13:00:00.000Z"], "updated_at": [null, "2025-06-12T13:00:00.000Z"], "description": [null, "French Standard VAT"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000081"]}', '2025-06-12 13:00:00', 'test');
INSERT INTO public.versions VALUES (12, 'Customer', '842b20fc-19ce-444a-8ff3-b4f07288d29f', 'create', NULL, NULL, '{"id": [null, "842b20fc-19ce-444a-8ff3-b4f07288d29f"], "url": [null, "http://kemmer-schuster.example/stephan"], "city": [null, "Paris"], "name": [null, "Actions Customer Co."], "slug": [null, "CON-0081-001"], "email": [null, "actions-customer@example.invalid"], "phone": [null, "587-966-4085"], "state": [null, "IDF"], "country": [null, "FR"], "zipcode": [null, "75002"], "currency": [null, "EUR"], "lastname": [null, "Schowalter"], "logo_url": [null, "http://bergnaum.example/allen"], "firstname": [null, "Dorethea"], "created_at": [null, "2025-06-12T13:00:00.000Z"], "legal_name": [null, "Rohan and Sons"], "updated_at": [null, "2025-06-12T13:00:00.000Z"], "external_id": [null, "invoice-actions-customer"], "legal_number": [null, "02-066-7686"], "address_line1": [null, "11 Rue des Actions"], "address_line2": [null, "Apt. 433"], "sequential_id": [null, 1], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000081"], "billing_entity_id": [null, "27ecbeb2-2af7-4590-9cb4-847e42f26cda"]}', '2025-06-12 13:00:00', 'test');
INSERT INTO public.versions VALUES (13, 'Customer::AppliedTax', '530478b2-2af6-4f1a-ae1b-7f2b76eac206', 'create', NULL, NULL, '{"id": [null, "530478b2-2af6-4f1a-ae1b-7f2b76eac206"], "tax_id": [null, "c0bb6c6e-7883-4203-a7b6-54b6637b83a8"], "created_at": [null, "2025-06-12T13:00:00.000Z"], "updated_at": [null, "2025-06-12T13:00:00.000Z"], "customer_id": [null, "842b20fc-19ce-444a-8ff3-b4f07288d29f"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000081"]}', '2025-06-12 13:00:00', 'test');
INSERT INTO public.versions VALUES (14, 'Subscription', '9b3f2e2e-3ae0-44e4-b5ea-a08c2446909a', 'create', NULL, NULL, '{"id": [null, "9b3f2e2e-3ae0-44e4-b5ea-a08c2446909a"], "status": [null, "active"], "plan_id": [null, "359c110d-ac3c-4d2e-87c5-b81fcbf0b562"], "created_at": [null, "2025-06-12T13:00:00.000Z"], "started_at": [null, "2025-06-01T00:00:00.000Z"], "updated_at": [null, "2025-06-12T13:00:00.000Z"], "customer_id": [null, "842b20fc-19ce-444a-8ff3-b4f07288d29f"], "external_id": [null, "invoice-actions-sub-1"], "activated_at": [null, "2025-06-01T00:00:00.000Z"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000081"], "subscription_at": [null, "2025-06-01T00:00:00.000Z"]}', '2025-06-12 13:00:00', 'test');
INSERT INTO public.versions VALUES (15, 'Invoice', '1a4a0d6e-0000-4000-8000-000000000084', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000084"], "number": ["", "LAR-6CDA-DRAFT"], "status": ["finalized", "draft"], "currency": [null, "EUR"], "created_at": [null, "2025-06-12T13:00:00.000Z"], "updated_at": [null, "2025-06-12T13:00:00.000Z"], "customer_id": [null, "842b20fc-19ce-444a-8ff3-b4f07288d29f"], "issuing_date": [null, "2025-06-11"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000081"], "payment_due_date": [null, "2025-06-11"], "billing_entity_id": [null, "27ecbeb2-2af7-4590-9cb4-847e42f26cda"], "expected_finalization_date": [null, "2025-06-11"], "organization_sequential_id": [0, 189108]}', '2025-06-12 13:00:00', 'test');


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

SELECT pg_catalog.setval('public.versions_id_seq', 15, true);


--
-- PostgreSQL database dump complete
--


