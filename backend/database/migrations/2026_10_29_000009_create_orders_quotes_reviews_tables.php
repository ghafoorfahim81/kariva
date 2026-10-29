<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- One table and one status machine for all four ways an order starts:
-- service (customer picks a service), direct (request to an actor), offer (accepted offer on a need),
-- invited (past job the provider adds and the customer confirms by OTP).
CREATE TABLE orders (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  origin text NOT NULL,
  customer_actor_id uuid NOT NULL REFERENCES actors(id),
  provider_actor_id uuid NOT NULL REFERENCES actors(id),
  service_id uuid,
  need_id uuid,
  need_kind text GENERATED ALWAYS AS (CASE WHEN need_id IS NULL THEN NULL ELSE 'service' END) STORED,
  offer_id uuid,
  title_snapshot text NOT NULL,
  description text,
  status text NOT NULL,
  agreed_amount numeric(14,2),
  currency text,
  price_unit text,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  completed_at timestamptz,
  CONSTRAINT orders_origin_valid CHECK (origin IN ('service','direct','offer','invited')),
  CONSTRAINT orders_status_valid CHECK (status IN ('invited','requested','quoted','accepted','provider_done','completed','rejected','cancelled','expired','disputed')),
  CONSTRAINT orders_currency_valid CHECK (currency IS NULL OR currency IN ('AFN','USD')),
  CONSTRAINT orders_price_unit_valid CHECK (price_unit IS NULL OR price_unit IN ('hour','day','meter','project','piece')),
  CONSTRAINT orders_amount_positive CHECK (agreed_amount IS NULL OR agreed_amount > 0),
  CONSTRAINT orders_amount_has_currency CHECK (agreed_amount IS NULL OR currency IS NOT NULL),
  CONSTRAINT orders_title_not_blank CHECK (btrim(title_snapshot) <> ''),
  CONSTRAINT orders_parties_differ CHECK (customer_actor_id <> provider_actor_id),
  CONSTRAINT orders_id_parties_unique UNIQUE (id, customer_actor_id, provider_actor_id),
  -- the origin decides which links must (not) be present
  CONSTRAINT orders_origin_links CHECK (
       (origin = 'service' AND service_id IS NOT NULL AND offer_id IS NULL AND need_id IS NULL)
    OR (origin = 'offer'   AND offer_id IS NOT NULL AND need_id IS NOT NULL AND service_id IS NULL)
    OR (origin IN ('direct','invited') AND service_id IS NULL AND offer_id IS NULL AND need_id IS NULL)
  ),
  -- invited past jobs live only as invited -> completed
  CONSTRAINT orders_invited_status CHECK ((status <> 'invited' OR origin = 'invited') AND (origin <> 'invited' OR status IN ('invited','completed'))),
  CONSTRAINT orders_completed_at_matches CHECK ((status = 'completed') = (completed_at IS NOT NULL)),
  -- once accepted there is always an agreed price (an invited past job may have none)
  CONSTRAINT orders_accepted_has_price CHECK (status NOT IN ('accepted','provider_done','completed') OR origin = 'invited' OR agreed_amount IS NOT NULL),
  -- the service / offer / need must belong to the right parties
  CONSTRAINT orders_service_of_provider FOREIGN KEY (service_id, provider_actor_id) REFERENCES services (id, actor_id),
  CONSTRAINT orders_offer_of_provider FOREIGN KEY (offer_id, provider_actor_id) REFERENCES offers (id, provider_actor_id),
  CONSTRAINT orders_offer_matches_need FOREIGN KEY (offer_id, need_id) REFERENCES offers (id, need_id),
  CONSTRAINT orders_need_of_customer FOREIGN KEY (need_id, customer_actor_id) REFERENCES needs (id, actor_id),
  CONSTRAINT orders_need_is_service_kind FOREIGN KEY (need_id, need_kind) REFERENCES needs (id, kind)
);
CREATE UNIQUE INDEX one_order_per_offer ON orders (offer_id) WHERE offer_id IS NOT NULL;
CREATE INDEX orders_customer_idx ON orders (customer_actor_id, created_at DESC);
CREATE INDEX orders_provider_idx ON orders (provider_actor_id, created_at DESC);
CREATE INDEX orders_open_idx ON orders (status) WHERE status IN ('requested','quoted','accepted','provider_done');

CREATE TABLE quotes (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id uuid NOT NULL REFERENCES orders(id),
  amount numeric(14,2) NOT NULL,
  currency text NOT NULL,
  price_unit text NOT NULL,
  days int,
  note text,
  status text NOT NULL DEFAULT 'pending',
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT quotes_amount_positive CHECK (amount > 0),
  CONSTRAINT quotes_currency_valid CHECK (currency IN ('AFN','USD')),
  CONSTRAINT quotes_price_unit_valid CHECK (price_unit IN ('hour','day','meter','project','piece')),
  CONSTRAINT quotes_days_positive CHECK (days IS NULL OR days > 0),
  CONSTRAINT quotes_status_valid CHECK (status IN ('pending','accepted','rejected','superseded'))
);
CREATE UNIQUE INDEX one_pending_quote ON quotes (order_id) WHERE status = 'pending';
CREATE INDEX quotes_order_idx ON quotes (order_id, created_at DESC);

CREATE TABLE order_events (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id uuid NOT NULL REFERENCES orders(id),
  event text NOT NULL,
  by_actor_id uuid REFERENCES actors(id),
  from_status text,
  to_status text,
  meta jsonb NOT NULL DEFAULT '{}'::jsonb,
  at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT order_events_event_format CHECK (event ~ '^[a-z_]+$'),
  CONSTRAINT order_events_status_valid CHECK (
    (from_status IS NULL OR from_status IN ('invited','requested','quoted','accepted','provider_done','completed','rejected','cancelled','expired','disputed'))
    AND (to_status IS NULL OR to_status IN ('invited','requested','quoted','accepted','provider_done','completed','rejected','cancelled','expired','disputed'))
  )
);
CREATE INDEX order_events_order_idx ON order_events (order_id, at);
CREATE TRIGGER order_events_append_only BEFORE UPDATE OR DELETE ON order_events
  FOR EACH ROW EXECUTE FUNCTION kariva_append_only();

CREATE TABLE reviews (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id uuid NOT NULL,
  customer_actor_id uuid NOT NULL,
  provider_actor_id uuid NOT NULL,
  stars smallint NOT NULL,
  tags text[] NOT NULL DEFAULT '{}',
  body text,
  reply_body text,
  replied_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT reviews_order_unique UNIQUE (order_id),
  CONSTRAINT reviews_stars_range CHECK (stars BETWEEN 1 AND 5),
  CONSTRAINT reviews_reply_pair CHECK ((reply_body IS NULL) = (replied_at IS NULL)),
  -- only the customer and provider of that very order can be on the review
  CONSTRAINT reviews_order_parties_fk FOREIGN KEY (order_id, customer_actor_id, provider_actor_id)
    REFERENCES orders (id, customer_actor_id, provider_actor_id)
);
CREATE INDEX reviews_provider_idx ON reviews (provider_actor_id, created_at DESC);

CREATE TABLE actor_stats (
  actor_id uuid PRIMARY KEY REFERENCES actors(id) ON DELETE CASCADE,
  completed_count int NOT NULL DEFAULT 0,
  rating_count int NOT NULL DEFAULT 0,
  rating_avg numeric(3,2),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT actor_stats_counts_valid CHECK (completed_count >= 0 AND rating_count >= 0),
  CONSTRAINT actor_stats_avg_range CHECK (rating_avg IS NULL OR rating_avg BETWEEN 1 AND 5),
  CONSTRAINT actor_stats_avg_matches_count CHECK ((rating_count = 0) = (rating_avg IS NULL))
);

-- ---------------------------------------------------------------- triggers

-- New orders: both sides active, valid first status, and the 5-per-month cap on invited past jobs.
CREATE OR REPLACE FUNCTION orders_before_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE invited_this_month int;
BEGIN
  IF EXISTS (SELECT 1 FROM actors WHERE id IN (NEW.customer_actor_id, NEW.provider_actor_id) AND status <> 'active') THEN
    RAISE EXCEPTION 'suspended or deleted actors cannot take part in new orders' USING ERRCODE = 'check_violation';
  END IF;

  -- unknown origins/statuses are left to the CHECK constraints, which give the clearer error
  IF NEW.origin IN ('service','direct','offer','invited') AND NEW.status IN ('invited','requested','quoted','accepted','provider_done','completed','rejected','cancelled','expired','disputed')
     AND NOT ((NEW.origin = 'invited' AND NEW.status = 'invited')
       OR (NEW.origin IN ('service','direct') AND NEW.status = 'requested')
       OR (NEW.origin = 'offer' AND NEW.status IN ('requested','accepted'))) THEN
    RAISE EXCEPTION 'invalid first status "%" for origin "%"', NEW.status, NEW.origin USING ERRCODE = 'check_violation';
  END IF;

  IF NEW.origin = 'invited' THEN
    PERFORM pg_advisory_xact_lock(hashtextextended('invited_orders:' || NEW.provider_actor_id::text, 0));
    SELECT count(*) INTO invited_this_month FROM orders
     WHERE provider_actor_id = NEW.provider_actor_id AND origin = 'invited'
       AND date_trunc('month', created_at AT TIME ZONE 'Asia/Kabul') = date_trunc('month', NEW.created_at AT TIME ZONE 'Asia/Kabul');
    IF invited_this_month >= 5 THEN
      RAISE EXCEPTION 'invited past job limit reached (5 per month)' USING ERRCODE = 'check_violation';
    END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER orders_before_insert BEFORE INSERT ON orders
  FOR EACH ROW EXECUTE FUNCTION orders_before_insert();

-- The status machine, plus the price/title lock that starts at "accepted".
CREATE OR REPLACE FUNCTION orders_before_update() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.status IS DISTINCT FROM OLD.status
     AND (OLD.status || '>' || NEW.status) <> ALL (ARRAY[
       'requested>quoted','requested>rejected','requested>cancelled','requested>expired',
       'quoted>accepted','quoted>rejected','quoted>cancelled','quoted>expired',
       'accepted>provider_done',
       'provider_done>completed','provider_done>disputed',
       'invited>completed']) THEN
    RAISE EXCEPTION 'illegal order status change % -> %', OLD.status, NEW.status USING ERRCODE = 'check_violation';
  END IF;

  IF OLD.status IN ('accepted','provider_done','completed','disputed') AND
     (NEW.agreed_amount, NEW.currency, NEW.price_unit, NEW.title_snapshot, NEW.customer_actor_id, NEW.provider_actor_id,
      NEW.origin, NEW.service_id, NEW.need_id, NEW.offer_id)
     IS DISTINCT FROM
     (OLD.agreed_amount, OLD.currency, OLD.price_unit, OLD.title_snapshot, OLD.customer_actor_id, OLD.provider_actor_id,
      OLD.origin, OLD.service_id, OLD.need_id, OLD.offer_id) THEN
    RAISE EXCEPTION 'price, title and parties are locked once an order is accepted' USING ERRCODE = 'check_violation';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER orders_before_update BEFORE UPDATE ON orders
  FOR EACH ROW EXECUTE FUNCTION orders_before_update();

-- Quotes: new quotes only while the order is open for quoting; nothing changes after completion.
CREATE OR REPLACE FUNCTION quotes_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE order_status text; target uuid;
BEGIN
  target := CASE WHEN TG_OP = 'DELETE' THEN OLD.order_id ELSE NEW.order_id END;
  SELECT status INTO order_status FROM orders WHERE id = target;
  IF order_status = 'completed' THEN
    RAISE EXCEPTION 'quotes of a completed order cannot change' USING ERRCODE = 'check_violation';
  END IF;
  IF TG_OP = 'INSERT' AND NEW.status = 'pending' AND order_status NOT IN ('requested','quoted') THEN
    RAISE EXCEPTION 'a quote can only be added while the order is requested or quoted' USING ERRCODE = 'check_violation';
  END IF;
  RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER quotes_guard BEFORE INSERT OR UPDATE OR DELETE ON quotes
  FOR EACH ROW EXECUTE FUNCTION quotes_guard();

-- Reviews only after completion.
CREATE OR REPLACE FUNCTION review_requires_completed() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM orders WHERE id = NEW.order_id AND status = 'completed') THEN
    RAISE EXCEPTION 'a review is only allowed for a completed order' USING ERRCODE = 'check_violation';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER review_requires_completed BEFORE INSERT ON reviews
  FOR EACH ROW EXECUTE FUNCTION review_requires_completed();

-- A page (business actor) with open orders can be neither deleted nor marked deleted.
CREATE OR REPLACE FUNCTION actors_block_delete_with_open_orders() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.type = 'business' AND (TG_OP = 'DELETE' OR NEW.status = 'deleted') AND EXISTS (
       SELECT 1 FROM orders
        WHERE (customer_actor_id = OLD.id OR provider_actor_id = OLD.id)
          AND status IN ('requested','quoted','accepted','provider_done')) THEN
    RAISE EXCEPTION 'a page with open orders cannot be deleted' USING ERRCODE = 'check_violation';
  END IF;
  RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER actors_block_delete_with_open_orders BEFORE DELETE OR UPDATE OF status ON actors
  FOR EACH ROW EXECUTE FUNCTION actors_block_delete_with_open_orders();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS actor_stats, reviews, order_events, quotes, orders CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS orders_before_insert(), orders_before_update(), quotes_guard(), review_requires_completed(), actors_block_delete_with_open_orders() CASCADE');
    }
};
