<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE provinces (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  code text NOT NULL UNIQUE,
  name text NOT NULL,
  name_ps text,
  name_en text,
  sort_order int NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT provinces_name_not_blank CHECK (btrim(name) <> '')
);

CREATE TABLE cities (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  province_id uuid NOT NULL REFERENCES provinces(id) ON DELETE RESTRICT,
  name text NOT NULL,
  name_ps text,
  name_en text,
  sort_order int NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT cities_name_not_blank CHECK (btrim(name) <> ''),
  CONSTRAINT cities_unique_in_province UNIQUE (province_id, name)
);
CREATE INDEX cities_province_idx ON cities (province_id);

CREATE TABLE categories (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  parent_id uuid REFERENCES categories(id) ON DELETE RESTRICT,
  slug text NOT NULL UNIQUE,
  name text NOT NULL,
  name_ps text,
  name_en text,
  aliases text[] NOT NULL DEFAULT '{}',
  icon text,
  sort_order int NOT NULL DEFAULT 0,
  is_active boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT categories_name_not_blank CHECK (btrim(name) <> ''),
  CONSTRAINT categories_not_own_parent CHECK (parent_id IS NULL OR parent_id <> id)
);
CREATE INDEX categories_parent_idx ON categories (parent_id);
CREATE INDEX categories_aliases_gin ON categories USING GIN (aliases);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS categories, cities, provinces CASCADE');
    }
};
