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

INSERT INTO public.organizations VALUES ('1a4a0d6e-0000-4000-8000-000000000051', 'Contract Graduated Org', '2025-05-02 11:00:00', '2025-05-02 11:00:00', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'jon@labadie-swift.test', NULL, NULL, NULL, NULL, NULL, 0, 'UTC', 'en', '{invoice.finalized,credit_note.created}', NULL, 0, 'USD', 0, 'CON-0051', false, '{}', false, true, false, '141434b8-e28f-475d-b968-78c9e7ce6d2c', '{email_password,google_oauth}', NULL, false, false, '{}', NULL, 'contract-graduated-org');


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

INSERT INTO public.billing_entities VALUES ('23d45a96-a81c-4aab-84bf-ce4623a569f3', '1a4a0d6e-0000-4000-8000-000000000051', NULL, NULL, NULL, NULL, NULL, NULL, 'UTC', 'USD', 'en', 'GRA-69F3', 'per_customer', true, NULL, 0, 0, 'bettye@friesen.example', '{invoice.finalized,credit_note.created}', false, NULL, NULL, NULL, 'Grady-Wiza', 'entity_e42323a3-d23e-46b5-b697-31c84cfd482c', NULL, 0, NULL, NULL, '2025-05-02 11:00:00', '2025-05-02 11:00:00', NULL, false, 'next_period_start', 'align_with_finalization_date', NULL, NULL);


--
-- Data for Name: customers; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.customers VALUES ('0cc1ce24-21c2-4021-b226-4cb9ac1e94b7', 'graduated-customer-1', 'Graduated Customer Co.', '1a4a0d6e-0000-4000-8000-000000000051', '2025-05-02 11:00:00', '2025-05-02 11:00:00', 'FR', '7 Rue des Paliers', 'Apt. 934', 'IDF', '75005', 'graduated@example.invalid', 'Paris', 'http://deckow.example/landon.olson', '(612) 054-6595', 'http://tremblay.test/andrea', 'Moore-Wiegand', '12-430-3363', NULL, NULL, 'CON-0051-001', 1, 'EUR', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'Delora', 'Mosciski', NULL, NULL, false, 0, NULL, false, 'customer', '23d45a96-a81c-4aab-84bf-ce4623a569f3', 0, NULL, NULL, false, '{}', NULL);


--
-- Data for Name: applied_add_ons; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: billable_metrics; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.billable_metrics VALUES ('1a4a0d6e-0000-4000-8000-000000000052', '1a4a0d6e-0000-4000-8000-000000000051', 'Tiered API Calls', 'tiered_calls', 'seeded sum metric for the graduated charge', '{}', 1, '2025-05-02 11:00:00', '2025-05-02 11:00:00', 'calls', NULL, false, NULL, NULL, '', NULL, NULL);


--
-- Data for Name: catalog_plans; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- Data for Name: plans; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.plans VALUES ('f3d0ead8-7295-4fed-b967-ad6f2203aa9c', '1a4a0d6e-0000-4000-8000-000000000051', 'Graduated Plan', '2025-05-02 11:00:00', '2025-05-02 11:00:00', 'graduated-plan', 1, 'arrears plan with a graduated charge', 4900, 'EUR', NULL, false, NULL, NULL, NULL, false, 'Graduated Plan Display', false);


--
-- Data for Name: charges; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.charges VALUES ('1a4a0d6e-0000-4000-8000-000000000053', '1a4a0d6e-0000-4000-8000-000000000052', '2025-05-02 11:00:00', '2025-05-02 11:00:00', 'f3d0ead8-7295-4fed-b967-ad6f2203aa9c', NULL, 1, '{"graduated_ranges": [{"to_value": 10, "from_value": 0, "flat_amount": "2", "per_unit_amount": "10"}, {"to_value": 20, "from_value": 11, "flat_amount": "3", "per_unit_amount": "5"}, {"to_value": null, "from_value": 21, "flat_amount": "3", "per_unit_amount": "5"}]}', NULL, false, 0, true, false, 'Tiered API calls', NULL, NULL, '1a4a0d6e-0000-4000-8000-000000000051', 'graduated-charge', false);


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

INSERT INTO public.api_keys VALUES ('cd2b6869-8c64-4df6-a145-93182cc0895d', '1a4a0d6e-0000-4000-8000-000000000051', '1a4a0d6e-0000-4000-8000-00000000005e', '2025-05-02 11:00:00', '2025-05-02 11:00:00', NULL, NULL, 'Graduated Capture Key', '{"fee": ["read", "write"], "tax": ["read", "write"], "plan": ["read", "write"], "x402": ["read", "write"], "alert": ["read", "write"], "event": ["read", "write"], "order": ["read", "write"], "quote": ["read", "write"], "add_on": ["read", "write"], "coupon": ["read", "write"], "wallet": ["read", "write"], "api_log": ["read", "write"], "feature": ["read", "write"], "invoice": ["read", "write"], "payment": ["read", "write"], "product": ["read", "write"], "analytic": ["read", "write"], "contract": ["read", "write"], "customer": ["read", "write"], "rate_card": ["read", "write"], "order_form": ["read", "write"], "credit_note": ["read", "write"], "activity_log": ["read", "write"], "organization": ["read", "write"], "security_log": ["read", "write"], "subscription": ["read", "write"], "applied_coupon": ["read", "write"], "billing_entity": ["read", "write"], "customer_usage": ["read", "write"], "lifetime_usage": ["read", "write"], "payment_method": ["read", "write"], "plan_rate_card": ["read", "write"], "billable_metric": ["read", "write"], "payment_receipt": ["read", "write"], "payment_request": ["read", "write"], "x402_connection": ["read", "write"], "product_category": ["read", "write"], "webhook_endpoint": ["read", "write"], "contract_rate_card": ["read", "write"], "wallet_transaction": ["read", "write"], "invoice_custom_section": ["read", "write"], "usage_attribution_type": ["read", "write"], "webhook_jwt_public_key": ["read", "write"]}');


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

INSERT INTO public.cached_aggregations VALUES ('597eb704-b8d7-4025-bb25-a2769911b42b', '1a4a0d6e-0000-4000-8000-000000000051', '2025-05-14 09:00:00', 'graduated-sub-1', '1a4a0d6e-0000-4000-8000-000000000053', NULL, 21.0, 21.0, NULL, '2025-05-02 11:00:00', '2025-05-02 11:00:00', '{}', NULL, NULL, '3ef3549c-231f-446a-9301-641bb4a9fafc', '[]');


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

INSERT INTO public.events VALUES ('5f8e2bf8-5492-4018-8154-5e5825610ef0', '1a4a0d6e-0000-4000-8000-000000000051', NULL, 'tr-graduated-1', 'tiered_calls', '{"calls": 10}', '2025-05-10 09:00:00', '2025-05-02 11:00:00', '2025-05-02 11:00:00', '{}', NULL, NULL, 'graduated-customer-1', 'graduated-sub-1', NULL);
INSERT INTO public.events VALUES ('b4b499cc-39ea-416c-b626-3829ed029456', '1a4a0d6e-0000-4000-8000-000000000051', NULL, 'tr-graduated-2', 'tiered_calls', '{"calls": 10}', '2025-05-11 09:00:00', '2025-05-02 11:00:00', '2025-05-02 11:00:00', '{}', NULL, NULL, 'graduated-customer-1', 'graduated-sub-1', NULL);
INSERT INTO public.events VALUES ('439b6eb6-9726-4f28-bd13-4d8a496ff006', '1a4a0d6e-0000-4000-8000-000000000051', NULL, 'tr-graduated-3', 'tiered_calls', '{"calls": 1}', '2025-05-14 09:00:00', '2025-05-02 11:00:00', '2025-05-02 11:00:00', '{}', NULL, NULL, 'graduated-customer-1', 'graduated-sub-1', NULL);


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

INSERT INTO public.versions VALUES (1, 'Organization', '1a4a0d6e-0000-4000-8000-000000000051', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000051"], "name": [null, "Contract Graduated Org"], "slug": [null, "contract-graduated-org"], "email": [null, "jon@labadie-swift.test"], "hmac_key": [null, "141434b8-e28f-475d-b968-78c9e7ce6d2c"], "created_at": [null, "2025-05-02T11:00:00.000Z"], "updated_at": [null, "2025-05-02T11:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "audit_logs_period": [30, null]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (2, 'BillingEntity', '23d45a96-a81c-4aab-84bf-ce4623a569f3', 'create', NULL, NULL, '{"id": [null, "23d45a96-a81c-4aab-84bf-ce4623a569f3"], "code": [null, "entity_e42323a3-d23e-46b5-b697-31c84cfd482c"], "name": [null, "Grady-Wiza"], "email": [null, "bettye@friesen.example"], "created_at": [null, "2025-05-02T11:00:00.000Z"], "updated_at": [null, "2025-05-02T11:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000051"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (3, 'BillingEntity', '23d45a96-a81c-4aab-84bf-ce4623a569f3', 'update', NULL, '{"id": "23d45a96-a81c-4aab-84bf-ce4623a569f3", "city": null, "code": "entity_e42323a3-d23e-46b5-b697-31c84cfd482c", "logo": null, "name": "Grady-Wiza", "email": "bettye@friesen.example", "phone": null, "state": null, "country": null, "zipcode": null, "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-05-02T11:00:00.000Z", "deleted_at": null, "einvoicing": false, "legal_name": null, "updated_at": "2025-05-02T11:00:00.000Z", "archived_at": null, "legal_number": null, "payment_term": null, "address_line1": null, "address_line2": null, "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "organization_id": "1a4a0d6e-0000-4000-8000-000000000051", "default_currency": "USD", "net_payment_term": 0, "eu_tax_management": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "document_number_prefix": null, "tax_identification_number": null, "applied_dunning_campaign_id": null, "finalize_zero_amount_invoice": true, "subscription_invoice_issuing_date_anchor": "next_period_start", "subscription_invoice_issuing_date_adjustment": "align_with_finalization_date"}', '{"document_number_prefix": [null, "GRA-69F3"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (4, 'Organization', '1a4a0d6e-0000-4000-8000-000000000051', 'update', NULL, '{"id": "1a4a0d6e-0000-4000-8000-000000000051", "city": null, "logo": null, "name": "Contract Graduated Org", "slug": "contract-graduated-org", "email": "jon@labadie-swift.test", "state": null, "api_key": null, "country": null, "zipcode": null, "hmac_key": "141434b8-e28f-475d-b968-78c9e7ce6d2c", "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-05-02T11:00:00.000Z", "legal_name": null, "updated_at": "2025-05-02T11:00:00.000Z", "max_wallets": null, "webhook_url": null, "legal_number": null, "address_line1": null, "address_line2": null, "feature_flags": [], "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "default_currency": "USD", "net_payment_term": 0, "audit_logs_period": null, "eu_tax_management": false, "custom_aggregation": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "premium_integrations": [], "authentication_methods": ["email_password", "google_oauth"], "document_number_prefix": null, "clickhouse_events_store": false, "tax_identification_number": null, "finalize_zero_amount_invoice": true, "clickhouse_deduplication_enabled": false}', '{"document_number_prefix": [null, "CON-0051"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (5, 'ApiKey', 'cd2b6869-8c64-4df6-a145-93182cc0895d', 'create', NULL, NULL, '{"id": [null, "cd2b6869-8c64-4df6-a145-93182cc0895d"], "name": [null, "Graduated Capture Key"], "value": [null, "36e9746c-5409-436f-967f-1fad76cbf89c"], "created_at": [null, "2025-05-02T11:00:00.000Z"], "updated_at": [null, "2025-05-02T11:00:00.000Z"], "permissions": [null, {"fee": ["read", "write"], "tax": ["read", "write"], "plan": ["read", "write"], "x402": ["read", "write"], "alert": ["read", "write"], "event": ["read", "write"], "order": ["read", "write"], "quote": ["read", "write"], "add_on": ["read", "write"], "coupon": ["read", "write"], "wallet": ["read", "write"], "api_log": ["read", "write"], "feature": ["read", "write"], "invoice": ["read", "write"], "payment": ["read", "write"], "product": ["read", "write"], "analytic": ["read", "write"], "contract": ["read", "write"], "customer": ["read", "write"], "rate_card": ["read", "write"], "order_form": ["read", "write"], "credit_note": ["read", "write"], "activity_log": ["read", "write"], "organization": ["read", "write"], "security_log": ["read", "write"], "subscription": ["read", "write"], "applied_coupon": ["read", "write"], "billing_entity": ["read", "write"], "customer_usage": ["read", "write"], "lifetime_usage": ["read", "write"], "payment_method": ["read", "write"], "plan_rate_card": ["read", "write"], "billable_metric": ["read", "write"], "payment_receipt": ["read", "write"], "payment_request": ["read", "write"], "x402_connection": ["read", "write"], "product_category": ["read", "write"], "webhook_endpoint": ["read", "write"], "contract_rate_card": ["read", "write"], "wallet_transaction": ["read", "write"], "invoice_custom_section": ["read", "write"], "usage_attribution_type": ["read", "write"], "webhook_jwt_public_key": ["read", "write"]}], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000051"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (6, 'Customer', '0cc1ce24-21c2-4021-b226-4cb9ac1e94b7', 'create', NULL, NULL, '{"id": [null, "0cc1ce24-21c2-4021-b226-4cb9ac1e94b7"], "url": [null, "http://deckow.example/landon.olson"], "city": [null, "Paris"], "name": [null, "Graduated Customer Co."], "slug": [null, "CON-0051-001"], "email": [null, "graduated@example.invalid"], "phone": [null, "(612) 054-6595"], "state": [null, "IDF"], "country": [null, "FR"], "zipcode": [null, "75005"], "currency": [null, "EUR"], "lastname": [null, "Mosciski"], "logo_url": [null, "http://tremblay.test/andrea"], "firstname": [null, "Delora"], "created_at": [null, "2025-05-02T11:00:00.000Z"], "legal_name": [null, "Moore-Wiegand"], "updated_at": [null, "2025-05-02T11:00:00.000Z"], "external_id": [null, "graduated-customer-1"], "legal_number": [null, "12-430-3363"], "address_line1": [null, "7 Rue des Paliers"], "address_line2": [null, "Apt. 934"], "sequential_id": [null, 1], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000051"], "billing_entity_id": [null, "23d45a96-a81c-4aab-84bf-ce4623a569f3"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (7, 'BillableMetric', '1a4a0d6e-0000-4000-8000-000000000052', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000052"], "code": [null, "tiered_calls"], "name": [null, "Tiered API Calls"], "created_at": [null, "2025-05-02T11:00:00.000Z"], "expression": [null, ""], "field_name": [null, "calls"], "updated_at": [null, "2025-05-02T11:00:00.000Z"], "description": [null, "seeded sum metric for the graduated charge"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000051"], "aggregation_type": [null, "sum_agg"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (8, 'Plan', 'f3d0ead8-7295-4fed-b967-ad6f2203aa9c', 'create', NULL, NULL, '{"id": [null, "f3d0ead8-7295-4fed-b967-ad6f2203aa9c"], "code": [null, "graduated-plan"], "name": [null, "Graduated Plan"], "interval": [null, "monthly"], "created_at": [null, "2025-05-02T11:00:00.000Z"], "updated_at": [null, "2025-05-02T11:00:00.000Z"], "description": [null, "arrears plan with a graduated charge"], "amount_cents": [null, 4900], "amount_currency": [null, "EUR"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000051"], "invoice_display_name": [null, "Graduated Plan Display"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (9, 'Charge', '1a4a0d6e-0000-4000-8000-000000000053', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-000000000053"], "code": [null, "graduated-charge"], "plan_id": [null, "f3d0ead8-7295-4fed-b967-ad6f2203aa9c"], "created_at": [null, "2025-05-02T11:00:00.000Z"], "properties": ["{}", {"graduated_ranges": [{"to_value": 10, "from_value": 0, "flat_amount": "2", "per_unit_amount": "10"}, {"to_value": 20, "from_value": 11, "flat_amount": "3", "per_unit_amount": "5"}, {"to_value": null, "from_value": 21, "flat_amount": "3", "per_unit_amount": "5"}]}], "updated_at": [null, "2025-05-02T11:00:00.000Z"], "charge_model": ["standard", "graduated"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-000000000051"], "billable_metric_id": [null, "1a4a0d6e-0000-4000-8000-000000000052"], "invoice_display_name": [null, "Tiered API calls"]}', '2025-05-02 11:00:00', 'test');
INSERT INTO public.versions VALUES (10, 'Plan', 'f3d0ead8-7295-4fed-b967-ad6f2203aa9c', 'update', NULL, '{"id": "f3d0ead8-7295-4fed-b967-ad6f2203aa9c", "code": "graduated-plan", "name": "Graduated Plan", "interval": "monthly", "parent_id": null, "created_at": "2025-05-02T11:00:00.000Z", "deleted_at": null, "updated_at": "2025-05-02T11:00:00.000Z", "description": "arrears plan with a graduated charge", "amount_cents": 4900, "trial_period": null, "pay_in_advance": false, "amount_currency": "EUR", "organization_id": "1a4a0d6e-0000-4000-8000-000000000051", "pending_deletion": false, "bill_charges_monthly": null, "invoice_display_name": "Graduated Plan Display", "bill_fixed_charges_monthly": false}', NULL, '2025-05-02 11:00:00', 'test');


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


