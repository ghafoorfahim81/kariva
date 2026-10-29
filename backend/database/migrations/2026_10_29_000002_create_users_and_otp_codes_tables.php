<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE users (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  phone_e164 text NOT NULL,
  email text,
  locale text NOT NULL DEFAULT 'fa',
  status text NOT NULL DEFAULT 'active',
  is_platform_admin boolean NOT NULL DEFAULT false,
  last_login_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  deleted_at timestamptz,
  CONSTRAINT users_phone_e164_unique UNIQUE (phone_e164),
  -- Afghan mobile in E.164: +93 then 9 digits starting with 7. Anonymized (soft-deleted) rows are exempt.
  CONSTRAINT users_phone_format CHECK (phone_e164 ~ '^\+937[0-9]{8}$' OR deleted_at IS NOT NULL),
  CONSTRAINT users_email_format CHECK (email IS NULL OR email ~ '^[^@[:space:]]+@[^@[:space:]]+$'),
  CONSTRAINT users_status_valid CHECK (status IN ('active','invited','suspended')),
  CONSTRAINT users_locale_valid CHECK (locale IN ('fa','ps','en'))
);
CREATE UNIQUE INDEX users_email_unique ON users (lower(email)) WHERE email IS NOT NULL AND deleted_at IS NULL;

CREATE TABLE otp_codes (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  phone_e164 text NOT NULL,
  purpose text NOT NULL DEFAULT 'login',
  code_hash text NOT NULL,
  attempts smallint NOT NULL DEFAULT 0,
  expires_at timestamptz NOT NULL,
  consumed_at timestamptz,
  ip_address inet,
  created_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT otp_phone_format CHECK (phone_e164 ~ '^\+937[0-9]{8}$'),
  CONSTRAINT otp_purpose_valid CHECK (purpose IN ('login','invited_job')),
  CONSTRAINT otp_attempts_max CHECK (attempts BETWEEN 0 AND 5),
  CONSTRAINT otp_expiry_after_creation CHECK (expires_at > created_at)
);
CREATE INDEX otp_codes_phone_created ON otp_codes (phone_e164, created_at DESC);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS otp_codes, users CASCADE');
    }
};
