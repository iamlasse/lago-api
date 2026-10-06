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

INSERT INTO public.organizations VALUES ('1a4a0d6e-0000-4000-8000-0000000000a3', 'Contract Collection Org', '2025-06-12 11:00:00', '2025-06-12 11:00:00', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'antoinette@wolf.example', NULL, NULL, NULL, NULL, NULL, 0, 'UTC', 'en', '{invoice.finalized,credit_note.created}', NULL, 0, 'USD', 0, 'CON-00A3', false, '{}', false, true, false, 'bed09d9c-71ea-4e8c-a8b4-361e69859ad8', '{email_password,google_oauth}', NULL, false, false, '{}', NULL, 'contract-collection-org');


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

INSERT INTO public.billing_entities VALUES ('d6cb5f33-ab90-4bf6-a8b1-d0a734f3f64a', '1a4a0d6e-0000-4000-8000-0000000000a3', NULL, NULL, NULL, NULL, NULL, NULL, 'UTC', 'USD', 'en', 'KIR-F64A', 'per_customer', true, NULL, 0, 0, 'shaneka.pouros@stamm.example', '{invoice.finalized,credit_note.created}', false, NULL, NULL, NULL, 'Kirlin LLC', 'entity_6c54553c-65d2-40b0-a1b7-d93248b5393c', NULL, 0, NULL, NULL, '2025-06-12 11:00:00', '2025-06-12 11:00:00', NULL, false, 'next_period_start', 'align_with_finalization_date', NULL, NULL);


--
-- Data for Name: customers; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.customers VALUES ('52c43609-b47e-4689-b35a-1c452e9466a5', 'col-cust-1', 'Collection Customer One', '1a4a0d6e-0000-4000-8000-0000000000a3', '2025-06-12 11:00:00', '2025-06-12 11:00:00', 'BM', '336 My Roads', 'Suite 794', 'Kentucky', '54254', 'willis@kassulke.example', 'Crooksside', 'http://lockman.test/allie', '948 574 7412', 'http://batz-grady.test/alvaro_walsh', 'Balistreri, Upton and Kassulke', '51-936-8005', NULL, NULL, 'CON-00A3-001', 1, 'EUR', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'Gayle', 'Ward', NULL, NULL, false, 0, NULL, false, 'customer', 'd6cb5f33-ab90-4bf6-a8b1-d0a734f3f64a', 0, NULL, NULL, false, '{}', NULL);
INSERT INTO public.customers VALUES ('33d1f6bc-f6e1-4243-81a9-61b21b84b717', 'col-cust-2', 'Collection Customer Two', '1a4a0d6e-0000-4000-8000-0000000000a3', '2025-06-12 11:00:00', '2025-06-12 11:00:00', 'IR', '629 Stroman Mall', 'Apt. 387', 'Alaska', '79119', 'joanne@roob.test', 'East Dennise', 'http://williamson-rempel.example/charleen', '235.086.8014', 'http://marvin.example/arnold', 'Torp-Hansen', '66-625-6512', NULL, NULL, 'CON-00A3-002', 2, 'EUR', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'Cristina', 'Aufderhar', NULL, NULL, false, 0, NULL, false, 'customer', 'd6cb5f33-ab90-4bf6-a8b1-d0a734f3f64a', 0, NULL, NULL, false, '{}', NULL);


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

INSERT INTO public.invoices VALUES ('af6ec558-0709-44ce-9a6b-88561cc81540', '2025-06-12 11:00:00', '2025-06-12 11:00:00', '2025-06-05', 0, 4000, 0, 0, 'KIR-F64A-DRAFT', NULL, NULL, '52c43609-b47e-4689-b35a-1c452e9466a5', 0, 1, 'UTC', 0, true, '1a4a0d6e-0000-4000-8000-0000000000a3', 4, 'EUR', 0, 0, 0, 0, 0, 0, '2025-06-05', 0, NULL, 710781, false, NULL, false, false, 0, 0, NULL, 0, false, NULL, 'd6cb5f33-ab90-4bf6-a8b1-d0a734f3f64a', NULL, NULL, NULL, NULL, '2025-06-11', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO public.invoices VALUES ('477dfab3-a2dc-4cb5-98ad-e253b75f6945', '2025-06-12 11:00:00', '2025-06-12 11:00:00', '2025-06-15', 0, 6000, 0, 1, 'KIR-F64A-DRAFT', NULL, NULL, '52c43609-b47e-4689-b35a-1c452e9466a5', 0, 1, 'UTC', 0, true, '1a4a0d6e-0000-4000-8000-0000000000a3', 4, 'EUR', 0, 0, 0, 0, 0, 0, '2025-06-15', 0, NULL, 777982, false, NULL, false, false, 0, 0, NULL, 0, false, NULL, 'd6cb5f33-ab90-4bf6-a8b1-d0a734f3f64a', NULL, NULL, NULL, NULL, '2025-06-11', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO public.invoices VALUES ('c6b2eb41-d0d2-4a1b-9045-aa1bb0ebe2f6', '2025-06-12 11:00:00', '2025-06-12 11:00:00', '2025-06-25', 0, 1500, 0, 1, 'KIR-F64A-DRAFT', NULL, NULL, '33d1f6bc-f6e1-4243-81a9-61b21b84b717', 0, 1, 'UTC', 0, true, '1a4a0d6e-0000-4000-8000-0000000000a3', 4, 'USD', 0, 0, 0, 0, 0, 0, '2025-06-25', 0, NULL, 19950, false, NULL, false, false, 0, 0, NULL, 0, false, NULL, 'd6cb5f33-ab90-4bf6-a8b1-d0a734f3f64a', NULL, NULL, NULL, NULL, '2025-06-11', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO public.invoices VALUES ('b5b749dc-fca2-4ce4-b4b3-7c01e96bc7ac', '2025-06-12 11:00:00', '2025-06-12 11:00:00', '2025-07-02', 0, 2500, 0, 2, 'KIR-F64A-DRAFT', NULL, NULL, '33d1f6bc-f6e1-4243-81a9-61b21b84b717', 0, 1, 'UTC', 0, true, '1a4a0d6e-0000-4000-8000-0000000000a3', 4, 'EUR', 0, 0, 0, 0, 0, 0, '2025-07-02', 0, NULL, 377387, false, NULL, false, false, 0, 0, NULL, 0, false, NULL, 'd6cb5f33-ab90-4bf6-a8b1-d0a734f3f64a', NULL, NULL, NULL, NULL, '2025-06-11', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO public.invoices VALUES ('158f60d2-bd46-4b49-8338-473410790937', '2025-06-12 11:00:00', '2025-06-12 11:00:00', '2025-06-28', 0, 9999, 0, 0, 'KIR-F64A-DRAFT', NULL, NULL, '52c43609-b47e-4689-b35a-1c452e9466a5', 0, 0, 'UTC', 0, true, '1a4a0d6e-0000-4000-8000-0000000000a3', 4, 'EUR', 0, 0, 0, 0, 0, 0, '2025-06-28', 0, NULL, 503043, false, NULL, false, false, 0, 0, NULL, 0, false, NULL, 'd6cb5f33-ab90-4bf6-a8b1-d0a734f3f64a', NULL, NULL, NULL, NULL, '2025-06-11', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);


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

INSERT INTO public.api_keys VALUES ('566245a8-85ab-4853-adfe-a4c04a48aa12', '1a4a0d6e-0000-4000-8000-0000000000a3', '1a4a0d6e-0000-4000-8000-0000000000b3', '2025-06-12 11:00:00', '2025-06-12 11:00:00', NULL, NULL, 'Collection Capture Key', '{"fee": ["read", "write"], "tax": ["read", "write"], "plan": ["read", "write"], "x402": ["read", "write"], "alert": ["read", "write"], "event": ["read", "write"], "order": ["read", "write"], "quote": ["read", "write"], "add_on": ["read", "write"], "coupon": ["read", "write"], "wallet": ["read", "write"], "api_log": ["read", "write"], "feature": ["read", "write"], "invoice": ["read", "write"], "payment": ["read", "write"], "product": ["read", "write"], "analytic": ["read", "write"], "contract": ["read", "write"], "customer": ["read", "write"], "rate_card": ["read", "write"], "order_form": ["read", "write"], "credit_note": ["read", "write"], "activity_log": ["read", "write"], "organization": ["read", "write"], "security_log": ["read", "write"], "subscription": ["read", "write"], "applied_coupon": ["read", "write"], "billing_entity": ["read", "write"], "customer_usage": ["read", "write"], "lifetime_usage": ["read", "write"], "payment_method": ["read", "write"], "plan_rate_card": ["read", "write"], "billable_metric": ["read", "write"], "payment_receipt": ["read", "write"], "payment_request": ["read", "write"], "x402_connection": ["read", "write"], "product_category": ["read", "write"], "webhook_endpoint": ["read", "write"], "contract_rate_card": ["read", "write"], "wallet_transaction": ["read", "write"], "invoice_custom_section": ["read", "write"], "usage_attribution_type": ["read", "write"], "webhook_jwt_public_key": ["read", "write"]}');


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

INSERT INTO public.versions VALUES (1, 'Organization', '1a4a0d6e-0000-4000-8000-0000000000a3', 'create', NULL, NULL, '{"id": [null, "1a4a0d6e-0000-4000-8000-0000000000a3"], "name": [null, "Contract Collection Org"], "slug": [null, "contract-collection-org"], "email": [null, "antoinette@wolf.example"], "hmac_key": [null, "bed09d9c-71ea-4e8c-a8b4-361e69859ad8"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "audit_logs_period": [30, null]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (2, 'BillingEntity', 'd6cb5f33-ab90-4bf6-a8b1-d0a734f3f64a', 'create', NULL, NULL, '{"id": [null, "d6cb5f33-ab90-4bf6-a8b1-d0a734f3f64a"], "code": [null, "entity_6c54553c-65d2-40b0-a1b7-d93248b5393c"], "name": [null, "Kirlin LLC"], "email": [null, "shaneka.pouros@stamm.example"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "email_settings": [[], ["invoice.finalized", "credit_note.created"]], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a3"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (3, 'BillingEntity', 'd6cb5f33-ab90-4bf6-a8b1-d0a734f3f64a', 'update', NULL, '{"id": "d6cb5f33-ab90-4bf6-a8b1-d0a734f3f64a", "city": null, "code": "entity_6c54553c-65d2-40b0-a1b7-d93248b5393c", "logo": null, "name": "Kirlin LLC", "email": "shaneka.pouros@stamm.example", "phone": null, "state": null, "country": null, "zipcode": null, "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-06-12T11:00:00.000Z", "deleted_at": null, "einvoicing": false, "legal_name": null, "updated_at": "2025-06-12T11:00:00.000Z", "archived_at": null, "legal_number": null, "payment_term": null, "address_line1": null, "address_line2": null, "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "organization_id": "1a4a0d6e-0000-4000-8000-0000000000a3", "default_currency": "USD", "net_payment_term": 0, "eu_tax_management": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "document_number_prefix": null, "tax_identification_number": null, "applied_dunning_campaign_id": null, "finalize_zero_amount_invoice": true, "subscription_invoice_issuing_date_anchor": "next_period_start", "subscription_invoice_issuing_date_adjustment": "align_with_finalization_date"}', '{"document_number_prefix": [null, "KIR-F64A"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (4, 'Organization', '1a4a0d6e-0000-4000-8000-0000000000a3', 'update', NULL, '{"id": "1a4a0d6e-0000-4000-8000-0000000000a3", "city": null, "logo": null, "name": "Contract Collection Org", "slug": "contract-collection-org", "email": "antoinette@wolf.example", "state": null, "api_key": null, "country": null, "zipcode": null, "hmac_key": "bed09d9c-71ea-4e8c-a8b4-361e69859ad8", "timezone": "UTC", "vat_rate": 0.0, "created_at": "2025-06-12T11:00:00.000Z", "legal_name": null, "updated_at": "2025-06-12T11:00:00.000Z", "max_wallets": null, "webhook_url": null, "legal_number": null, "address_line1": null, "address_line2": null, "feature_flags": [], "email_settings": ["invoice.finalized", "credit_note.created"], "invoice_footer": null, "document_locale": "en", "default_currency": "USD", "net_payment_term": 0, "audit_logs_period": null, "eu_tax_management": false, "custom_aggregation": false, "document_numbering": "per_customer", "invoice_grace_period": 0, "premium_integrations": [], "authentication_methods": ["email_password", "google_oauth"], "document_number_prefix": null, "clickhouse_events_store": false, "tax_identification_number": null, "finalize_zero_amount_invoice": true, "clickhouse_deduplication_enabled": false}', '{"document_number_prefix": [null, "CON-00A3"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (5, 'ApiKey', '566245a8-85ab-4853-adfe-a4c04a48aa12', 'create', NULL, NULL, '{"id": [null, "566245a8-85ab-4853-adfe-a4c04a48aa12"], "name": [null, "Collection Capture Key"], "value": [null, "d43948bf-0936-44bd-bf28-e2f90c4b150c"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "permissions": [null, {"fee": ["read", "write"], "tax": ["read", "write"], "plan": ["read", "write"], "x402": ["read", "write"], "alert": ["read", "write"], "event": ["read", "write"], "order": ["read", "write"], "quote": ["read", "write"], "add_on": ["read", "write"], "coupon": ["read", "write"], "wallet": ["read", "write"], "api_log": ["read", "write"], "feature": ["read", "write"], "invoice": ["read", "write"], "payment": ["read", "write"], "product": ["read", "write"], "analytic": ["read", "write"], "contract": ["read", "write"], "customer": ["read", "write"], "rate_card": ["read", "write"], "order_form": ["read", "write"], "credit_note": ["read", "write"], "activity_log": ["read", "write"], "organization": ["read", "write"], "security_log": ["read", "write"], "subscription": ["read", "write"], "applied_coupon": ["read", "write"], "billing_entity": ["read", "write"], "customer_usage": ["read", "write"], "lifetime_usage": ["read", "write"], "payment_method": ["read", "write"], "plan_rate_card": ["read", "write"], "billable_metric": ["read", "write"], "payment_receipt": ["read", "write"], "payment_request": ["read", "write"], "x402_connection": ["read", "write"], "product_category": ["read", "write"], "webhook_endpoint": ["read", "write"], "contract_rate_card": ["read", "write"], "wallet_transaction": ["read", "write"], "invoice_custom_section": ["read", "write"], "usage_attribution_type": ["read", "write"], "webhook_jwt_public_key": ["read", "write"]}], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a3"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (6, 'Customer', '52c43609-b47e-4689-b35a-1c452e9466a5', 'create', NULL, NULL, '{"id": [null, "52c43609-b47e-4689-b35a-1c452e9466a5"], "url": [null, "http://lockman.test/allie"], "city": [null, "Crooksside"], "name": [null, "Collection Customer One"], "slug": [null, "CON-00A3-001"], "email": [null, "willis@kassulke.example"], "phone": [null, "948 574 7412"], "state": [null, "Kentucky"], "country": [null, "BM"], "zipcode": [null, "54254"], "currency": [null, "EUR"], "lastname": [null, "Ward"], "logo_url": [null, "http://batz-grady.test/alvaro_walsh"], "firstname": [null, "Gayle"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "legal_name": [null, "Balistreri, Upton and Kassulke"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "external_id": [null, "col-cust-1"], "legal_number": [null, "51-936-8005"], "address_line1": [null, "336 My Roads"], "address_line2": [null, "Suite 794"], "sequential_id": [null, 1], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a3"], "billing_entity_id": [null, "d6cb5f33-ab90-4bf6-a8b1-d0a734f3f64a"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (7, 'Customer', '33d1f6bc-f6e1-4243-81a9-61b21b84b717', 'create', NULL, NULL, '{"id": [null, "33d1f6bc-f6e1-4243-81a9-61b21b84b717"], "url": [null, "http://williamson-rempel.example/charleen"], "city": [null, "East Dennise"], "name": [null, "Collection Customer Two"], "slug": [null, "CON-00A3-002"], "email": [null, "joanne@roob.test"], "phone": [null, "235.086.8014"], "state": [null, "Alaska"], "country": [null, "IR"], "zipcode": [null, "79119"], "currency": [null, "EUR"], "lastname": [null, "Aufderhar"], "logo_url": [null, "http://marvin.example/arnold"], "firstname": [null, "Cristina"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "legal_name": [null, "Torp-Hansen"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "external_id": [null, "col-cust-2"], "legal_number": [null, "66-625-6512"], "address_line1": [null, "629 Stroman Mall"], "address_line2": [null, "Apt. 387"], "sequential_id": [null, 2], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a3"], "billing_entity_id": [null, "d6cb5f33-ab90-4bf6-a8b1-d0a734f3f64a"]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (8, 'Invoice', 'af6ec558-0709-44ce-9a6b-88561cc81540', 'create', NULL, NULL, '{"id": [null, "af6ec558-0709-44ce-9a6b-88561cc81540"], "number": ["", "KIR-F64A-DRAFT"], "currency": [null, "EUR"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "52c43609-b47e-4689-b35a-1c452e9466a5"], "issuing_date": [null, "2025-06-05"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a3"], "payment_due_date": [null, "2025-06-05"], "billing_entity_id": [null, "d6cb5f33-ab90-4bf6-a8b1-d0a734f3f64a"], "total_amount_cents": [0, 4000], "expected_finalization_date": [null, "2025-06-11"], "organization_sequential_id": [0, 710781]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (9, 'Invoice', '477dfab3-a2dc-4cb5-98ad-e253b75f6945', 'create', NULL, NULL, '{"id": [null, "477dfab3-a2dc-4cb5-98ad-e253b75f6945"], "number": ["", "KIR-F64A-DRAFT"], "currency": [null, "EUR"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "52c43609-b47e-4689-b35a-1c452e9466a5"], "issuing_date": [null, "2025-06-15"], "payment_status": ["pending", "succeeded"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a3"], "payment_due_date": [null, "2025-06-15"], "billing_entity_id": [null, "d6cb5f33-ab90-4bf6-a8b1-d0a734f3f64a"], "total_amount_cents": [0, 6000], "expected_finalization_date": [null, "2025-06-11"], "organization_sequential_id": [0, 777982]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (10, 'Invoice', 'c6b2eb41-d0d2-4a1b-9045-aa1bb0ebe2f6', 'create', NULL, NULL, '{"id": [null, "c6b2eb41-d0d2-4a1b-9045-aa1bb0ebe2f6"], "number": ["", "KIR-F64A-DRAFT"], "currency": [null, "USD"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "33d1f6bc-f6e1-4243-81a9-61b21b84b717"], "issuing_date": [null, "2025-06-25"], "payment_status": ["pending", "succeeded"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a3"], "payment_due_date": [null, "2025-06-25"], "billing_entity_id": [null, "d6cb5f33-ab90-4bf6-a8b1-d0a734f3f64a"], "total_amount_cents": [0, 1500], "expected_finalization_date": [null, "2025-06-11"], "organization_sequential_id": [0, 19950]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (11, 'Invoice', 'b5b749dc-fca2-4ce4-b4b3-7c01e96bc7ac', 'create', NULL, NULL, '{"id": [null, "b5b749dc-fca2-4ce4-b4b3-7c01e96bc7ac"], "number": ["", "KIR-F64A-DRAFT"], "currency": [null, "EUR"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "33d1f6bc-f6e1-4243-81a9-61b21b84b717"], "issuing_date": [null, "2025-07-02"], "payment_status": ["pending", "failed"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a3"], "payment_due_date": [null, "2025-07-02"], "billing_entity_id": [null, "d6cb5f33-ab90-4bf6-a8b1-d0a734f3f64a"], "total_amount_cents": [0, 2500], "expected_finalization_date": [null, "2025-06-11"], "organization_sequential_id": [0, 377387]}', '2025-06-12 11:00:00', 'test');
INSERT INTO public.versions VALUES (12, 'Invoice', '158f60d2-bd46-4b49-8338-473410790937', 'create', NULL, NULL, '{"id": [null, "158f60d2-bd46-4b49-8338-473410790937"], "number": ["", "KIR-F64A-DRAFT"], "status": ["finalized", "draft"], "currency": [null, "EUR"], "created_at": [null, "2025-06-12T11:00:00.000Z"], "updated_at": [null, "2025-06-12T11:00:00.000Z"], "customer_id": [null, "52c43609-b47e-4689-b35a-1c452e9466a5"], "issuing_date": [null, "2025-06-28"], "organization_id": [null, "1a4a0d6e-0000-4000-8000-0000000000a3"], "payment_due_date": [null, "2025-06-28"], "billing_entity_id": [null, "d6cb5f33-ab90-4bf6-a8b1-d0a734f3f64a"], "total_amount_cents": [0, 9999], "expected_finalization_date": [null, "2025-06-11"], "organization_sequential_id": [0, 503043]}', '2025-06-12 11:00:00', 'test');


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


