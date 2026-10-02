--
-- PostgreSQL database dump
--

CREATE TABLE public.customers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    external_id character varying NOT NULL,
    metadata jsonb DEFAULT '"{}"'::jsonb,
    created_at timestamp(6) without time zone NOT NULL,
    note text DEFAULT 'a;b(c) [x]'::text,
    CONSTRAINT customers_pkey PRIMARY KEY (id)
);

CREATE TABLE public.invoice_subscriptions (
    id uuid NOT NULL,
    invoice_id uuid NOT NULL,
    subscription_id uuid NOT NULL,
    status integer DEFAULT 0 NOT NULL,
    PRIMARY KEY (id)
);

CREATE TABLE public.enriched_events PARTITION OF public.events
    FOR VALUES FROM ('2025-01-01') TO ('2025-02-01');
