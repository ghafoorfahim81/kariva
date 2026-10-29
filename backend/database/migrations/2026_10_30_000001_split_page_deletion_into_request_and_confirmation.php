<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Spec §11 matrix: any owner may *request* page deletion, only the primary owner *confirms* it.
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS actors_deletion_by_primary_owner ON actors;
DROP FUNCTION IF EXISTS actors_deletion_by_primary_owner();

ALTER TABLE actors ADD COLUMN deletion_confirmed_by uuid REFERENCES users(id);
ALTER TABLE actors DROP CONSTRAINT actors_business_delete_needs_request;
ALTER TABLE actors ADD CONSTRAINT actors_business_delete_needs_request CHECK (
  type = 'person' OR status <> 'deleted' OR (deletion_requested_by IS NOT NULL AND deletion_confirmed_by IS NOT NULL)
);

CREATE OR REPLACE FUNCTION actors_deletion_by_owners() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.deletion_requested_by IS NOT NULL
     AND (TG_OP = 'INSERT' OR NEW.deletion_requested_by IS DISTINCT FROM OLD.deletion_requested_by)
     AND NEW.type = 'business'
     AND NOT EXISTS (SELECT 1 FROM actor_members WHERE actor_id = NEW.id AND user_id = NEW.deletion_requested_by AND role = 'owner') THEN
    RAISE EXCEPTION 'only an owner can request page deletion' USING ERRCODE = 'check_violation';
  END IF;
  IF NEW.deletion_confirmed_by IS NOT NULL
     AND (TG_OP = 'INSERT' OR NEW.deletion_confirmed_by IS DISTINCT FROM OLD.deletion_confirmed_by)
     AND NOT EXISTS (SELECT 1 FROM actor_members WHERE actor_id = NEW.id AND user_id = NEW.deletion_confirmed_by AND is_primary) THEN
    RAISE EXCEPTION 'only the primary owner can confirm page deletion' USING ERRCODE = 'check_violation';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER actors_deletion_by_owners BEFORE INSERT OR UPDATE OF deletion_requested_by, deletion_confirmed_by ON actors
  FOR EACH ROW EXECUTE FUNCTION actors_deletion_by_owners();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS actors_deletion_by_owners ON actors;
DROP FUNCTION IF EXISTS actors_deletion_by_owners();
ALTER TABLE actors DROP CONSTRAINT actors_business_delete_needs_request;
ALTER TABLE actors DROP COLUMN deletion_confirmed_by;
ALTER TABLE actors ADD CONSTRAINT actors_business_delete_needs_request CHECK (type = 'person' OR status <> 'deleted' OR deletion_requested_by IS NOT NULL);
SQL);
    }
};
