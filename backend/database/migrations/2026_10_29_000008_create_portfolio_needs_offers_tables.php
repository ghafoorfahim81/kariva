<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE portfolio_items (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  actor_id uuid NOT NULL REFERENCES actors(id) ON DELETE CASCADE,
  service_id uuid,
  title text NOT NULL,
  description text,
  sort_order int NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT portfolio_title_not_blank CHECK (btrim(title) <> ''),
  -- a work sample may only point at a service of the same actor
  CONSTRAINT portfolio_service_same_actor FOREIGN KEY (service_id, actor_id) REFERENCES services (id, actor_id)
);
CREATE INDEX portfolio_items_actor_idx ON portfolio_items (actor_id, sort_order);

-- kind = service: ends in an order. kind = job: conversation only, never an order.
CREATE TABLE needs (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  actor_id uuid NOT NULL REFERENCES actors(id) ON DELETE CASCADE,
  category_id uuid NOT NULL REFERENCES categories(id),
  city_id uuid REFERENCES cities(id),
  kind text NOT NULL,
  status text NOT NULL DEFAULT 'open',
  title text NOT NULL,
  description text,
  expires_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT needs_id_kind_unique UNIQUE (id, kind),
  CONSTRAINT needs_id_actor_unique UNIQUE (id, actor_id),
  CONSTRAINT needs_kind_valid CHECK (kind IN ('service','job')),
  CONSTRAINT needs_status_valid CHECK (status IN ('open','closed','cancelled','expired')),
  CONSTRAINT needs_title_not_blank CHECK (btrim(title) <> '')
);
CREATE INDEX needs_open_idx ON needs (category_id, city_id, created_at DESC) WHERE status = 'open';
CREATE INDEX needs_actor_idx ON needs (actor_id);

-- Offers exist only for service needs: need_kind is a constant column tied to needs(id, kind).
CREATE TABLE offers (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  need_id uuid NOT NULL,
  need_kind text GENERATED ALWAYS AS ('service') STORED,
  provider_actor_id uuid NOT NULL REFERENCES actors(id),
  amount numeric(14,2) NOT NULL,
  currency text NOT NULL,
  price_unit text NOT NULL,
  days int,
  message text,
  status text NOT NULL DEFAULT 'pending',
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT offers_need_service_only FOREIGN KEY (need_id, need_kind) REFERENCES needs (id, kind) ON DELETE CASCADE,
  CONSTRAINT offers_one_per_provider UNIQUE (need_id, provider_actor_id),
  CONSTRAINT offers_id_provider_unique UNIQUE (id, provider_actor_id),
  CONSTRAINT offers_id_need_unique UNIQUE (id, need_id),
  CONSTRAINT offers_amount_positive CHECK (amount > 0),
  CONSTRAINT offers_currency_valid CHECK (currency IN ('AFN','USD')),
  CONSTRAINT offers_price_unit_valid CHECK (price_unit IN ('hour','day','meter','project','piece')),
  CONSTRAINT offers_days_positive CHECK (days IS NULL OR days > 0),
  CONSTRAINT offers_status_valid CHECK (status IN ('pending','accepted','rejected','withdrawn'))
);
CREATE UNIQUE INDEX one_accepted_offer_per_need ON offers (need_id) WHERE status = 'accepted';
CREATE INDEX offers_provider_idx ON offers (provider_actor_id);

CREATE OR REPLACE FUNCTION offers_not_on_own_need() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF EXISTS (SELECT 1 FROM needs WHERE id = NEW.need_id AND actor_id = NEW.provider_actor_id) THEN
    RAISE EXCEPTION 'an actor cannot offer on its own need' USING ERRCODE = 'check_violation';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM needs WHERE id = NEW.need_id AND status = 'open') AND NEW.status = 'pending' THEN
    RAISE EXCEPTION 'offers can only be made on open needs' USING ERRCODE = 'check_violation';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER offers_not_on_own_need BEFORE INSERT ON offers
  FOR EACH ROW EXECUTE FUNCTION offers_not_on_own_need();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS offers, needs, portfolio_items CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS offers_not_on_own_need() CASCADE');
    }
};
