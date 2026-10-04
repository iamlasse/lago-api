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

INSERT INTO public.organizations VALUES ('1a4a0d6e-0000-4000-8000-000000000121', 'Contract Credit Notes Org', '2025-06-08 11:00:00', '2025-06-08 11:00:00', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'lyman@hills-purdy.test', NULL, NULL, NULL, NULL, NULL, 0, 'UTC', 'en', '{invoice.finalized,credit_note.created}', NULL, 0, 'USD', 0, 'CON-0121', false, '{}', false, true, false, 'e7433056-c6d4-485b-9c72-a93548771d21', '{email_password,google_oauth}', NULL, false, false, '{}', NULL, 'contract-credit-notes-org');


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

INSERT INTO public.billing_entities VALUES ('1903788a-92da-4005-890b-3b18b8c3a934', '1a4a0d6e-0000-4000-8000-000000000121', NULL, NULL, NULL, NULL, NULL, NULL, 'UTC', 'USD', 'en', 'BUC-A934', 'per_customer', true, NULL, 0, 0, 'erna.langworth@schneider-medhurst.test', '{invoice.finalized,credit_note.created}', false, NULL, NULL, NULL, 'Buckridge, Jones and White', 'entity_47aaab09-8322-4248-aafe-3951d68f8d96', NULL, 0, NULL, NULL, '2025-06-08 11:00:00', '2025-06-08 11:00:00', NULL, false, 'next_period_start', 'align_with_finalization_date', NULL, NULL);


--
-- Data for Name: customers; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.customers VALUES ('1f271dc5-46fc-4ce8-b8f0-de053b87d5fc', 'credit-customer-1', 'Credit Customer Co.', '1a4a0d6e-0000-4000-8000-000000000121', '2025-06-08 11:00:00', '2025-06-08 11:00:00', 'FR', '5 Rue des Avoirs', 'Suite 966', 'IDF', '75008', 'credit-customer@example.invalid', 'Paris', 'http://ward.test/tony_doyle', '269-696-1688', 'http://hirthe.test/serita_ferry', 'Buckridge Inc', '84-388-6157', NULL, NULL, 'CON-0121-001', 1, 'EUR', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'Cre', 'Dit', NULL, NULL, false, 0, NULL, false, 'customer', '1903788a-92da-4005-890b-3b18b8c3a934', 0, NULL, NULL, false, '{}', NULL);


--
-- Data for Name: applied_add_ons; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: billable_metrics; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.billable_metrics VALUES ('1a4a0d6e-0000-4000-8000-000000000122', '1a4a0d6e-0000-4000-8000-000000000121', 'Credit API Calls', 'credit_calls', 'seeded sum metric', '{}', 1, '2025-06-08 11:00:00', '2025-06-08 11:00:00', 'calls', NULL, false, NULL, NULL, '', NULL, NULL);


--
-- Data for Name: catalog_plans; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: plans; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.plans VALUES ('7c6615b7-1265-4c77-a982-dd228cfcbbe0', '1a4a0d6e-0000-4000-8000-000000000121', 'Credit Plan', '2025-06-08 11:00:00', '2025-06-08 11:00:00', 'credit-plan', 1, 'arrears plan, flat 49 EUR', 4900, 'EUR', NULL, false, NULL, NULL, NULL, false, 'Credit Plan Display', false);


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

INSERT INTO public.invoices VALUES ('1b9a5bcf-b3cd-434c-bce9-1eb144b13d24', '2025-06-01 00:00:00', '2025-06-01 00:00:00', '2025-06-01', 0, 4900, 0, 0, 'BUC-A934-001-001', 1, NULL, '1f271dc5-46fc-4ce8-b8f0-de053b87d5fc', 0, 1, 'UTC', 0, true, '1a4a0d6e-0000-4000-8000-000000000121', 4, 'EUR', 4900, 0, 0, 0, 4900, 4900, '2025-06-01', 0, NULL, 0, false, NULL, false, false, 0, 0, NULL, 0, false, NULL, '1903788a-92da-4005-890b-3b18b8c3a934', NULL, '2025-06-01 00:00:00', NULL, NULL, '2025-06-01', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'BUC-A934-001-001 Credit Customer Co. Cre Dit Buckridge Inc credit-customer-1 credit-customer@example.invalid');


--
-- Data for Name: rate_card_rates; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: rate_overrides; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: subscriptions; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.subscriptions VALUES ('87e14c66-e2f2-4d98-92df-3221a87a73da', '1f271dc5-46fc-4ce8-b8f0-de053b87d5fc', '7c6615b7-1265-4c77-a982-dd228cfcbbe0', 1, NULL, NULL, '2025-05-01 00:00:00', '2025-06-08 11:00:00', '2025-06-08 11:00:00', NULL, 'Credit Sub', 'credit-sub-1', 0, '2025-05-01 00:00:00', NULL, NULL, '1a4a0d6e-0000-4000-8000-000000000121', NULL, 'generate', NULL, 'provider', false, false, NULL, NULL, NULL, '2025-05-01 00:00:00', NULL, true, false, NULL, NULL, NULL);


--
-- Data for Name: fees; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.fees VALUES ('80f1dab4-abb7-4156-8ee2-4176a7f786fc', '1b9a5bcf-b3cd-434c-bce9-1eb144b13d24', NULL, '87e14c66-e2f2-4d98-92df-3221a87a73da', 4900, 'EUR', 0, 0, '2025-06-01 00:00:00', '2025-06-01 00:00:00', 1.0, NULL, '{"timestamp": "2025-06-01T00:00:00.000Z", "to_datetime": "2025-05-31T23:59:59.999Z", "from_datetime": "2025-05-01T00:00:00.000Z", "charges_duration": 31, "charges_to_datetime": "2025-05-31T23:59:59.999Z", "charges_from_datetime": "2025-05-01T00:00:00.000Z", "fixed_charges_duration": 31, "fixed_charges_to_datetime": "2025-05-31T23:59:59.999Z", "fixed_charges_from_datetime": "2025-05-01T00:00:00.000Z"}', 2, 'Subscription', '87e14c66-e2f2-4d98-92df-3221a87a73da', NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 4900, false, 0.00000, NULL, NULL, 49.000000000000000, '{"plan_amount_cents": 4900}', NULL, '{}', NULL, NULL, 4900.000000000000000, 0.000000000000000, 1, '1a4a0d6e-0000-4000-8000-000000000121', '1903788a-92da-4005-890b-3b18b8c3a934', 0.00000, NULL, false, NULL, NULL, NULL, NULL, NULL, NULL);


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

INSERT INTO public.api_keys VALUES ('b9d79af2-89f4-4da8-9585-b5b6a8c14d8f', '1a4a0d6e-0000-4000-8000-000000000121', '1a4a0d6e-0000-4000-8000-0000000001dd', '2025-06-08 11:00:00', '2025-06-08 11:00:00', NULL, NULL, 'Credit Notes Capture Key', '{"fee": ["read", "write"], "tax": ["read", "write"], "plan": ["read", "write"], "x402": ["read", "write"], "alert": ["read", "write"], "event": ["read", "write"], "order": ["read", "write"], "quote": ["read", "write"], "add_on": ["read", "write"], "coupon": ["read", "write"], "wallet": ["read", "write"], "api_log": ["read", "write"], "feature": ["read", "write"], "invoice": ["read", "write"], "payment": ["read", "write"], "product": ["read", "write"], "analytic": ["read", "write"], "contract": ["read", "write"], "customer": ["read", "write"], "rate_card": ["read", "write"], "order_form": ["read", "write"], "credit_note": ["read", "write"], "activity_log": ["read", "write"], "organization": ["read", "write"], "security_log": ["read", "write"], "subscription": ["read", "write"], "applied_coupon": ["read", "write"], "billing_entity": ["read", "write"], "customer_usage": ["read", "write"], "lifetime_usage": ["read", "write"], "payment_method": ["read", "write"], "plan_rate_card": ["read", "write"], "billable_metric": ["read", "write"], "payment_receipt": ["read", "write"], "payment_request": ["read", "write"], "x402_connection": ["read", "write"], "product_category": ["read", "write"], "webhook_endpoint": ["read", "write"], "contract_rate_card": ["read", "write"], "wallet_transaction": ["read", "write"], "invoice_custom_section": ["read", "write"], "usage_attribution_type": ["read", "write"], "webhook_jwt_public_key": ["read", "write"]}');


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

INSERT INTO public.invoice_subscriptions VALUES ('1a2e363f-30a5-4690-a80a-eeb94348e55d', '1b9a5bcf-b3cd-434c-bce9-1eb144b13d24', '87e14c66-e2f2-4d98-92df-3221a87a73da', '2025-06-01 00:00:00', '2025-06-01 00:00:00', true, '2025-06-01 00:00:00', '2025-05-01 00:00:00', '2025-05-31 23:59:59.999999', '2025-05-01 00:00:00', '2025-05-31 23:59:59.999999', 'subscription_periodic', '1a4a0d6e-0000-4000-8000-000000000121', NULL, '2025-05-01 00:00:00', '2025-05-31 23:59:59.999999');


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

INSERT INTO public.versions VALUES (1, 'Organization', '1a4a0d6e-0000-4000-8000-000000000121', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000121"], "name": [null, "Contract Credit Notes Org"], "slug": [null, "contract-credit-notes-org"], "email": [null, "lyman@hills-purdy.test"], "hmac_key": [null, "e7433056-c6d4-485b-9c72-a93548771d21"], "created_at": [null, "2025-06-08T11:00:00.000Z"], "updated_at": [null, "2025-06-08T11:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "audit_logs_period": [30, null]}', '2025-06-08 11:00:00', 'test');
INSERT INTO public.versions VALUES (2, 'BillingEntity', '1903788a-92da-4005-890b-3b18b8c3a934', 'create', NULL, NULL, '{"id": [null, "1903788a-92da-4005-890b-3b18b8c3a934"], "code": [null, "entity_47aaab09-8322-4248-aafe-3951d68f8d96"], "name": [null, "Buckridge, Jones and White"], "email": [null, "erna.langworth@schneider-medhurst.test"], "created_at": [null, "2025-06-08T11:00:00.000Z"], "updated_at": [null, "2025-06-08T11:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000121"]}', '2025-06-08 11:00:00', 'test');
INSERT INTO public.versions VALUES (3, 'BillingEntity', '1903788a-92da-4005-890b-3b18b8c3a934', 'update', NULL, '{"id": "1903788a-92da-4005-890b-3b18b8c3a934", "city": null, "code": "entity_47aaab09-8322-4248-aafe-3951d68f8d96", "logo": null, "name": "Buckridge, Jones and White", "email": "erna.langworth@schneider-medhurst.test", "phone": null, "state": null, "country": null, "zipcode": null, "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-06-08T11:00:00.000Z", "deleted_at": null, "einvoicing": false, "legal_name": null, "updated_at": "2025-06-08T11:00:00.000Z", "archived_at": null, "legal_number": null, "payment_term": null, "address_line1": null, "address_line2": null, "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "organization_id": "1a4a0d6e-0000-4000-8000-000000000121", "default_currency": "USD", "net_payment_term": 0, "eu_tax_management": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "document_number_prefix": null, "tax_identification_number": null, "applied_dunning_campaign_id": null, "finalize_zero_amount_invoice": true, "subscription_invoice_issuing_date_anchor": "next_period_start", "subscription_invoice_issuing_date_adjustment": "align_with_finalization_date"}', '{"document_number_prefix": [null, "BUC-A934"]}', '2025-06-08 11:00:00', 'test');
INSERT INTO public.versions VALUES (4, 'Organization', '1a4a0d6e-0000-4000-8000-000000000121', 'update', NULL, '{"id": "1a4a0d6e-0000-4000-8000-000000000121", "city": null, "logo": null, "name": "Contract Credit Notes Org", "slug": "contract-credit-notes-org", "email": "lyman@hills-purdy.test", "state": null, "api_key": null, "country": null, "zipcode": null, "hmac_key": "e7433056-c6d4-485b-9c72-a93548771d21", "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-06-08T11:00:00.000Z", "legal_name": null, "updated_at": "2025-06-08T11:00:00.000Z", "max_wallets": null, "webhook_url": null, "legal_number": null, "address_line1": null, "address_line2": null, "feature_flags": [], "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "default_currency": "USD", "net_payment_term": 0, "audit_logs_period": null, "eu_tax_management": false, "custom_aggregation": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "premium_integrations": [], "authentication_methods": ["email_password", "google_oauth"], "document_number_prefix": null, "clickhouse_events_store": false, "tax_identification_number": null, "finalize_zero_amount_invoice": true, "clickhouse_deduplication_enabled": false}', '{"document_number_prefix": [null, "CON-0121"]}', '2025-06-08 11:00:00', 'test');
INSERT INTO public.versions VALUES (5, 'ApiKey', 'b9d79af2-89f4-4da8-9585-b5b6a8c14d8f', 'create', NULL, NULL, '{"id": [null, "b9d79af2-89f4-4da8-9585-b5b6a8c14d8f"], "name": [null, "Credit Notes Capture Key"], "value": [null, "f48e9203-ab52-4977-9f6a-9ae5ee85b444"], "created_at": [null, "2025-06-08T11:00:00.000Z"], "updated_at": [null, "2025-06-08T11:00:00.000Z"], "permissions": [null, {"fee": ["read", "write"], "tax": ["read", "write"], "plan": ["read", "write"], "x402": ["read", "write"], "alert": ["read", "write"], "event": ["read", "write"], "order": ["read", "write"], "quote": ["read", "write"], "add_on": ["read", "write"], "coupon": ["read", "write"], "wallet": ["read", "write"], "api_log": ["read", "write"], "feature": ["read", "write"], "invoice": ["read", "write"], "payment": ["read", "write"], "product": ["read", "write"], "analytic": ["read", "write"], "contract": ["read", "write"], "customer": ["read", "write"], "rate_card": ["read", "write"], "order_form": ["read", "write"], "credit_note": ["read", "write"], "activity_log": ["read", "write"], "organization": ["read", "write"], "security_log": ["read", "write"], "subscription": ["read", "write"], "applied_coupon": ["read", "write"], "billing_entity": ["read", "write"], "customer_usage": ["read", "write"], "lifetime_usage": ["read", "write"], "payment_method": ["read", "write"], "plan_rate_card": ["read", "write"], "billable_metric": ["read", "write"], "payment_receipt": ["read", "write"], "payment_request": ["read", "write"], "x402_connection": ["read", "write"], "product_category": ["read", "write"], "webhook_endpoint": ["read", "write"], "contract_rate_card": ["read", "write"], "wallet_transaction": ["read", "write"], "invoice_custom_section": ["read", "write"], "usage_attribution_type": ["read", "write"], "webhook_jwt_public_key": ["read", "write"]}], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000121"]}', '2025-06-08 11:00:00', 'test');
INSERT INTO public.versions VALUES (6, 'Customer', '1f271dc5-46fc-4ce8-b8f0-de053b87d5fc', 'create', NULL, NULL, '{"id": [null, "1f271dc5-46fc-4ce8-b8f0-de053b87d5fc"], "url": [null, "http://ward.test/tony_doyle"], "city": [null, "Paris"], "name": [null, "Credit Customer Co."], "slug": [null, "CON-0121-001"], "email": [null, "credit-customer@example.invalid"], "phone": [null, "269-696-1688"], "state": [null, "IDF"], "country": [null, "FR"], "zipcode": [null, "75008"], "currency": [null, "EUR"], "lastname": [null, "Dit"], "logo_url": [null, "http://hirthe.test/serita_ferry"], "firstname": [null, "Cre"], "created_at": [null, "2025-06-08T11:00:00.000Z"], "legal_name": [null, "Buckridge Inc"], "updated_at": [null, "2025-06-08T11:00:00.000Z"], "external_id": [null, "credit-customer-1"], "legal_number": [null, "84-388-6157"], "address_line1": [null, "5 Rue des Avoirs"], "address_line2": [null, "Suite 966"], "sequential_id": [null, 1], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000121"], "billing_entity_id": [null, "1903788a-92da-4005-890b-3b18b8c3a934"]}', '2025-06-08 11:00:00', 'test');
INSERT INTO public.versions VALUES (7, 'BillableMetric', '1a4a0d6e-0000-4000-8000-000000000122', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000122"], "code": [null, "credit_calls"], "name": [null, "Credit API Calls"], "created_at": [null, "2025-06-08T11:00:00.000Z"], "expression": [null, ""], "field_name": [null, "calls"], "updated_at": [null, "2025-06-08T11:00:00.000Z"], "description": [null, "seeded sum metric"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000121"], "aggregation_type": [null, "sum_agg"]}', '2025-06-08 11:00:00', 'test');
INSERT INTO public.versions VALUES (8, 'Plan', '7c6615b7-1265-4c77-a982-dd228cfcbbe0', 'create', NULL, NULL, '{"id": [null, "7c6615b7-1265-4c77-a982-dd228cfcbbe0"], "code": [null, "credit-plan"], "name": [null, "Credit Plan"], "interval": [null, "monthly"], "created_at": [null, "2025-06-08T11:00:00.000Z"], "updated_at": [null, "2025-06-08T11:00:00.000Z"], "description": [null, "arrears plan, flat 49 EUR"], "amount_cents": [null, 4900], "amount_currency": [null, "EUR"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000121"], "invoice_display_name": [null, "Credit Plan Display"]}', '2025-06-08 11:00:00', 'test');
INSERT INTO public.versions VALUES (9, 'Subscription', '87e14c66-e2f2-4d98-92df-3221a87a73da', 'create', NULL, NULL, '{"id": [null, "87e14c66-e2f2-4d98-92df-3221a87a73da"], "name": [null, "Credit Sub"], "status": [null, "active"], "plan_id": [null, "7c6615b7-1265-4c77-a982-dd228cfcbbe0"], "created_at": [null, "2025-06-08T11:00:00.000Z"], "started_at": [null, "2025-05-01T00:00:00.000Z"], "updated_at": [null, "2025-06-08T11:00:00.000Z"], "customer_id": [null, "1f271dc5-46fc-4ce8-b8f0-de053b87d5fc"], "external_id": [null, "credit-sub-1"], "activated_at": [null, "2025-05-01T00:00:00.000Z"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000121"], "subscription_at": [null, "2025-05-01T00:00:00.000Z"]}', '2025-06-08 11:00:00', 'test');
INSERT INTO public.versions VALUES (10, 'Invoice', '1b9a5bcf-b3cd-434c-bce9-1eb144b13d24', 'create', NULL, NULL, '{"id": [null, "1b9a5bcf-b3cd-434c-bce9-1eb144b13d24"], "number": ["", "BUC-A934-DRAFT"], "status": ["finalized", "generating"], "currency": [null, "EUR"], "created_at": [null, "2025-06-01T00:00:00.000Z"], "updated_at": [null, "2025-06-01T00:00:00.000Z"], "customer_id": [null, "1f271dc5-46fc-4ce8-b8f0-de053b87d5fc"], "issuing_date": [null, "2025-06-01"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000121"], "payment_due_date": [null, "2025-06-01"], "billing_entity_id": [null, "1903788a-92da-4005-890b-3b18b8c3a934"], "expected_finalization_date": [null, "2025-06-01"]}', '2025-06-01 00:00:00', 'test');
INSERT INTO public.versions VALUES (11, 'Invoice', '1b9a5bcf-b3cd-434c-bce9-1eb144b13d24', 'update', NULL, '{"id": "1b9a5bcf-b3cd-434c-bce9-1eb144b13d24", "file": null, "number": "BUC-A934-DRAFT", "status": "generating", "currency": "EUR", "timezone": "UTC", "xml_file": null, "voided_at": null, "created_at": "2025-06-01T00:00:00.000Z", "tax_status": null, "taxes_rate": 0.0, "updated_at": "2025-06-01T00:00:00.000Z", "customer_id": "1f271dc5-46fc-4ce8-b8f0-de053b87d5fc", "self_billed": false, "finalized_at": null, "invoice_type": "subscription", "issuing_date": "2025-06-01", "payment_term": null, "search_terms": null, "skip_charges": false, "sequential_id": null, "payment_status": "pending", "version_number": 4, "organization_id": "1a4a0d6e-0000-4000-8000-000000000121", "payment_overdue": false, "net_payment_term": 0, "payment_attempts": 0, "payment_due_date": "2025-06-01", "billing_entity_id": "1903788a-92da-4005-890b-3b18b8c3a934", "fees_amount_cents": 0, "payment_method_id": null, "voided_invoice_id": null, "taxes_amount_cents": 0, "total_amount_cents": 0, "payment_term_source": null, "applied_grace_period": null, "coupons_amount_cents": 0, "purchase_order_number": null, "ready_to_be_refreshed": false, "skip_automatic_payment": null, "payment_dispute_lost_at": null, "total_paid_amount_cents": 0, "credit_notes_amount_cents": 0, "expected_finalization_date": "2025-06-01", "organization_sequential_id": 0, "prepaid_credit_amount_cents": 0, "billing_entity_sequential_id": null, "ready_for_payment_processing": true, "prepaid_granted_credit_amount_cents": null, "prepaid_purchased_credit_amount_cents": null, "sub_total_excluding_taxes_amount_cents": 0, "sub_total_including_taxes_amount_cents": 0, "progressive_billing_credit_amount_cents": 0}', '{"fees_amount_cents": [0, 4900], "total_amount_cents": [0, 4900], "sub_total_excluding_taxes_amount_cents": [0, 4900], "sub_total_including_taxes_amount_cents": [0, 4900]}', '2025-06-01 00:00:00', 'test');
INSERT INTO public.versions VALUES (12, 'Invoice', '1b9a5bcf-b3cd-434c-bce9-1eb144b13d24', 'update', NULL, '{"id": "1b9a5bcf-b3cd-434c-bce9-1eb144b13d24", "file": null, "number": "BUC-A934-DRAFT", "status": "generating", "currency": "EUR", "timezone": "UTC", "xml_file": null, "voided_at": null, "created_at": "2025-06-01T00:00:00.000Z", "tax_status": null, "taxes_rate": 0.0, "updated_at": "2025-06-01T00:00:00.000Z", "customer_id": "1f271dc5-46fc-4ce8-b8f0-de053b87d5fc", "self_billed": false, "finalized_at": null, "invoice_type": "subscription", "issuing_date": "2025-06-01", "payment_term": null, "search_terms": "BUC-A934-DRAFT Credit Customer Co. Cre Dit Buckridge Inc credit-customer-1 credit-customer@example.invalid", "skip_charges": false, "sequential_id": null, "payment_status": "pending", "version_number": 4, "organization_id": "1a4a0d6e-0000-4000-8000-000000000121", "payment_overdue": false, "net_payment_term": 0, "payment_attempts": 0, "payment_due_date": "2025-06-01", "billing_entity_id": "1903788a-92da-4005-890b-3b18b8c3a934", "fees_amount_cents": 4900, "payment_method_id": null, "voided_invoice_id": null, "taxes_amount_cents": 0, "total_amount_cents": 4900, "payment_term_source": null, "applied_grace_period": null, "coupons_amount_cents": 0, "purchase_order_number": null, "ready_to_be_refreshed": false, "skip_automatic_payment": null, "payment_dispute_lost_at": null, "total_paid_amount_cents": 0, "credit_notes_amount_cents": 0, "expected_finalization_date": "2025-06-01", "organization_sequential_id": 0, "prepaid_credit_amount_cents": 0, "billing_entity_sequential_id": null, "ready_for_payment_processing": true, "prepaid_granted_credit_amount_cents": null, "prepaid_purchased_credit_amount_cents": null, "sub_total_excluding_taxes_amount_cents": 4900, "sub_total_including_taxes_amount_cents": 4900, "progressive_billing_credit_amount_cents": 0}', '{"number": ["BUC-A934-DRAFT", "BUC-A934-001-001"], "status": ["generating", "finalized"], "finalized_at": [null, "2025-06-01T00:00:00.000Z"], "sequential_id": [null, 1]}', '2025-06-01 00:00:00', 'test');


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

SELECT pg_catalog.setval('public.versions_id_seq', 12, true);


--
-- PostgreSQL database dump complete
--


