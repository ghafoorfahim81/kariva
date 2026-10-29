<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE actors (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  type text NOT NULL,
  personal_user_id uuid REFERENCES users(id) ON DELETE RESTRICT,
  category_id uuid REFERENCES categories(id) ON DELETE SET NULL,
  city_id uuid REFERENCES cities(id) ON DELETE SET NULL,
  display_name text NOT NULL,
  bio text,
  contact_phone_e164 text,
  whatsapp_e164 text,
  search_text text NOT NULL DEFAULT '',
  status text NOT NULL DEFAULT 'active',
  verified_at timestamptz,
  deletion_requested_by uuid REFERENCES users(id),
  deletion_requested_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  deleted_at timestamptz,
  CONSTRAINT actors_personal_user_unique UNIQUE (personal_user_id),
  CONSTRAINT actors_id_type_unique UNIQUE (id, type),
  CONSTRAINT actors_type_valid CHECK (type IN ('person','business')),
  -- a person actor belongs to exactly one user; a business never does
  CONSTRAINT actors_type_matches_owner CHECK ((type = 'person') = (personal_user_id IS NOT NULL)),
  CONSTRAINT actors_status_valid CHECK (status IN ('active','suspended','deleted')),
  CONSTRAINT actors_name_not_blank CHECK (btrim(display_name) <> ''),
  CONSTRAINT actors_contact_phone_format CHECK (contact_phone_e164 IS NULL OR contact_phone_e164 ~ '^\+937[0-9]{8}$'),
  CONSTRAINT actors_whatsapp_format CHECK (whatsapp_e164 IS NULL OR whatsapp_e164 ~ '^\+937[0-9]{8}$'),
  CONSTRAINT actors_deletion_request_pair CHECK ((deletion_requested_by IS NULL) = (deletion_requested_at IS NULL)),
  -- a business page can only be deleted after its primary owner asked for it
  CONSTRAINT actors_business_delete_needs_request CHECK (type = 'person' OR status <> 'deleted' OR deletion_requested_by IS NOT NULL)
);
CREATE INDEX actors_category_city_idx ON actors (category_id, city_id) WHERE status = 'active';
CREATE INDEX actors_city_idx ON actors (city_id) WHERE status = 'active';
CREATE INDEX actors_search_text_trgm ON actors USING GIN (search_text gin_trgm_ops);

CREATE TABLE actor_members (
  actor_id uuid NOT NULL REFERENCES actors(id) ON DELETE CASCADE,
  user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  role text NOT NULL,
  is_primary boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (actor_id, user_id),
  CONSTRAINT actor_members_role_valid CHECK (role IN ('owner','admin','editor','viewer')),
  CONSTRAINT primary_must_be_owner CHECK (NOT is_primary OR role = 'owner')
);
CREATE UNIQUE INDEX one_primary_owner ON actor_members (actor_id) WHERE is_primary;
CREATE INDEX actor_members_user_idx ON actor_members (user_id);

CREATE TABLE actor_invitations (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  actor_id uuid NOT NULL REFERENCES actors(id),
  kind text NOT NULL,
  role text NOT NULL,
  invited_phone_e164 text NOT NULL,
  invited_user_id uuid REFERENCES users(id),
  invited_by_user_id uuid NOT NULL REFERENCES users(id),
  status text NOT NULL DEFAULT 'pending',
  expires_at timestamptz NOT NULL,
  responded_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT actor_invitations_kind_valid CHECK (kind IN ('member','transfer_primary')),
  CONSTRAINT actor_invitations_role_valid CHECK (role IN ('owner','admin','editor','viewer')),
  CONSTRAINT actor_invitations_status_valid CHECK (status IN ('pending','accepted','declined','expired','cancelled')),
  CONSTRAINT actor_invitations_phone_format CHECK (invited_phone_e164 ~ '^\+937[0-9]{8}$'),
  CONSTRAINT actor_invitations_transfer_is_owner CHECK (kind <> 'transfer_primary' OR role = 'owner'),
  CONSTRAINT actor_invitations_responded_pair CHECK (((status IN ('accepted','declined')) = (responded_at IS NOT NULL)) OR status IN ('expired','cancelled'))
);
CREATE UNIQUE INDEX one_pending_invitation ON actor_invitations (actor_id, invited_phone_e164, kind) WHERE status = 'pending';

-- ---------------------------------------------------------------- triggers

-- Every user owns exactly one personal actor (checked at commit, so both rows can be created in one transaction).
CREATE OR REPLACE FUNCTION users_require_personal_actor() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM actors WHERE personal_user_id = NEW.id) THEN
    RAISE EXCEPTION 'user % has no personal actor', NEW.id USING ERRCODE = 'check_violation';
  END IF;
  RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER users_require_personal_actor AFTER INSERT ON users
  DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION users_require_personal_actor();

-- Actor type and its personal user never change after creation.
CREATE OR REPLACE FUNCTION actors_identity_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.type IS DISTINCT FROM OLD.type OR NEW.personal_user_id IS DISTINCT FROM OLD.personal_user_id THEN
    RAISE EXCEPTION 'actor type and personal user are immutable' USING ERRCODE = 'check_violation';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER actors_identity_immutable BEFORE UPDATE ON actors
  FOR EACH ROW EXECUTE FUNCTION actors_identity_immutable();

-- Only the primary owner may ask for the page to be deleted.
CREATE OR REPLACE FUNCTION actors_deletion_by_primary_owner() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.deletion_requested_by IS NOT NULL
     AND (TG_OP = 'INSERT' OR NEW.deletion_requested_by IS DISTINCT FROM OLD.deletion_requested_by) THEN
    IF NEW.type = 'business' AND NOT EXISTS (
         SELECT 1 FROM actor_members
          WHERE actor_id = NEW.id AND user_id = NEW.deletion_requested_by AND is_primary) THEN
      RAISE EXCEPTION 'only the primary owner can request page deletion' USING ERRCODE = 'check_violation';
    END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER actors_deletion_by_primary_owner BEFORE UPDATE OF deletion_requested_by ON actors
  FOR EACH ROW EXECUTE FUNCTION actors_deletion_by_primary_owner();

-- The last owner can never be removed or demoted, and the primary owner must be handed over first.
CREATE OR REPLACE FUNCTION actor_members_protect_owners() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM actors WHERE id = OLD.actor_id) THEN
    RETURN OLD; -- the actor itself is being deleted
  END IF;
  IF TG_OP = 'DELETE' AND OLD.is_primary THEN
    RAISE EXCEPTION 'the primary owner cannot be removed; transfer primary ownership first' USING ERRCODE = 'check_violation';
  END IF;
  IF OLD.role = 'owner' AND (TG_OP = 'DELETE' OR NEW.role <> 'owner') THEN
    IF NOT EXISTS (SELECT 1 FROM actor_members WHERE actor_id = OLD.actor_id AND role = 'owner' AND user_id <> OLD.user_id) THEN
      RAISE EXCEPTION 'the last owner of an actor cannot be removed' USING ERRCODE = 'check_violation';
    END IF;
  END IF;
  RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER actor_members_protect_owners BEFORE DELETE OR UPDATE OF role ON actor_members
  FOR EACH ROW EXECUTE FUNCTION actor_members_protect_owners();

-- Who may invite: the primary owner moves primary ownership; owners/admins invite members, admins never create owners.
CREATE OR REPLACE FUNCTION actor_invitations_check_inviter() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE inviter_role text; inviter_primary boolean;
BEGIN
  SELECT role, is_primary INTO inviter_role, inviter_primary
    FROM actor_members WHERE actor_id = NEW.actor_id AND user_id = NEW.invited_by_user_id;
  IF NEW.kind = 'transfer_primary' THEN
    IF inviter_primary IS NOT TRUE THEN
      RAISE EXCEPTION 'only the primary owner can transfer primary ownership' USING ERRCODE = 'check_violation';
    END IF;
  ELSE
    IF inviter_role IS NULL OR inviter_role NOT IN ('owner','admin') THEN
      RAISE EXCEPTION 'only owners and admins can invite members' USING ERRCODE = 'check_violation';
    END IF;
    IF inviter_role = 'admin' AND NEW.role = 'owner' THEN
      RAISE EXCEPTION 'admins cannot invite owners' USING ERRCODE = 'check_violation';
    END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER actor_invitations_check_inviter BEFORE INSERT ON actor_invitations
  FOR EACH ROW EXECUTE FUNCTION actor_invitations_check_inviter();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS actor_invitations, actor_members, actors CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS users_require_personal_actor(), actors_identity_immutable(), actors_deletion_by_primary_owner(), actor_members_protect_owners(), actor_invitations_check_inviter() CASCADE');
    }
};
