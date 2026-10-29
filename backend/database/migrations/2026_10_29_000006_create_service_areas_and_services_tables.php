<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE actor_service_areas (
  actor_id uuid NOT NULL REFERENCES actors(id) ON DELETE CASCADE,
  city_id uuid NOT NULL REFERENCES cities(id) ON DELETE CASCADE,
  created_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (actor_id, city_id)
);
CREATE INDEX actor_service_areas_city_idx ON actor_service_areas (city_id);

-- No currency conversion, tax or commission anywhere: amounts keep their own currency and unit.
CREATE TABLE services (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  actor_id uuid NOT NULL REFERENCES actors(id) ON DELETE CASCADE,
  category_id uuid NOT NULL REFERENCES categories(id),
  title text NOT NULL,
  description text,
  delivery_mode text NOT NULL DEFAULT 'onsite',
  pricing_type text NOT NULL DEFAULT 'negotiable',
  price_amount numeric(14,2),
  currency text,
  price_unit text,
  search_text text NOT NULL DEFAULT '',
  status text NOT NULL DEFAULT 'draft',
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT services_id_actor_unique UNIQUE (id, actor_id),
  CONSTRAINT services_title_not_blank CHECK (btrim(title) <> ''),
  CONSTRAINT services_delivery_mode_valid CHECK (delivery_mode IN ('onsite','online','both')),
  CONSTRAINT services_pricing_type_valid CHECK (pricing_type IN ('fixed','from','negotiable')),
  CONSTRAINT services_status_valid CHECK (status IN ('draft','active','paused','archived')),
  CONSTRAINT services_currency_valid CHECK (currency IS NULL OR currency IN ('AFN','USD')),
  CONSTRAINT services_price_unit_valid CHECK (price_unit IS NULL OR price_unit IN ('hour','day','meter','project','piece')),
  CONSTRAINT services_price_required CHECK (
    pricing_type = 'negotiable'
    OR (price_amount IS NOT NULL AND price_amount > 0 AND currency IS NOT NULL AND price_unit IS NOT NULL)
  ),
  CONSTRAINT services_negotiable_has_no_amount CHECK (pricing_type <> 'negotiable' OR price_amount IS NULL)
);
CREATE INDEX services_actor_idx ON services (actor_id);
CREATE INDEX services_category_idx ON services (category_id) WHERE status = 'active';
CREATE INDEX services_search_text_trgm ON services USING GIN (search_text gin_trgm_ops);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS services, actor_service_areas CASCADE');
    }
};
