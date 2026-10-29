<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- The document number is never stored; only the photo lives in `media` (private, with a purge date).
CREATE TABLE verification_requests (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  actor_id uuid NOT NULL,
  kind text NOT NULL,
  requested_by uuid NOT NULL REFERENCES users(id),
  status text NOT NULL DEFAULT 'pending',
  admin_note text,
  reviewed_by uuid REFERENCES users(id),
  reviewed_at timestamptz,
  files_purge_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT verification_kind_valid CHECK (kind IN ('person','business')),
  CONSTRAINT verification_status_valid CHECK (status IN ('pending','approved','rejected')),
  -- the kind of verification must match the actor type
  CONSTRAINT verification_actor_type_fk FOREIGN KEY (actor_id, kind) REFERENCES actors (id, type) ON DELETE CASCADE,
  CONSTRAINT verification_reviewed_when_decided CHECK (status = 'pending' OR (reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL))
);
CREATE UNIQUE INDEX one_pending_verification ON verification_requests (actor_id, kind) WHERE status = 'pending';
CREATE INDEX verification_purge_idx ON verification_requests (files_purge_at) WHERE files_purge_at IS NOT NULL;

CREATE TABLE audit_logs (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id uuid REFERENCES users(id),
  actor_id uuid REFERENCES actors(id),
  action text NOT NULL,
  subject_type text,
  subject_id uuid,
  meta jsonb NOT NULL DEFAULT '{}'::jsonb,
  ip_address inet,
  created_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT audit_action_not_blank CHECK (btrim(action) <> '')
);
CREATE INDEX audit_logs_user_idx ON audit_logs (user_id, created_at DESC);
CREATE INDEX audit_logs_actor_idx ON audit_logs (actor_id, created_at DESC);
CREATE INDEX audit_logs_subject_idx ON audit_logs (subject_type, subject_id);
CREATE TRIGGER audit_logs_append_only BEFORE UPDATE OR DELETE ON audit_logs
  FOR EACH ROW EXECUTE FUNCTION kariva_append_only();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS audit_logs, verification_requests CASCADE');
    }
};
