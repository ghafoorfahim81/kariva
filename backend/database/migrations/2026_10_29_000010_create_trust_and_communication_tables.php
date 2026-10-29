<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE reports (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  reporter_user_id uuid NOT NULL REFERENCES users(id),
  reportable_type text NOT NULL,
  reportable_id uuid NOT NULL,
  reason text NOT NULL,
  details text,
  status text NOT NULL DEFAULT 'open',
  resolved_by uuid REFERENCES users(id),
  resolved_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT reports_type_valid CHECK (reportable_type IN ('actor','service','review','message')),
  CONSTRAINT reports_status_valid CHECK (status IN ('open','resolved','dismissed')),
  CONSTRAINT reports_reason_not_blank CHECK (btrim(reason) <> ''),
  CONSTRAINT reports_resolved_when_closed CHECK ((status = 'open') = (resolved_at IS NULL))
);
-- one open report per user per subject
CREATE UNIQUE INDEX one_open_report_per_user ON reports (reporter_user_id, reportable_type, reportable_id) WHERE status = 'open';
CREATE INDEX reports_subject_idx ON reports (reportable_type, reportable_id);
CREATE INDEX reports_open_idx ON reports (created_at) WHERE status = 'open';

CREATE TABLE favorites (
  user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  favoritable_type text NOT NULL,
  favoritable_id uuid NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (user_id, favoritable_type, favoritable_id),
  CONSTRAINT favorites_type_valid CHECK (favoritable_type IN ('actor','service','need'))
);
CREATE INDEX favorites_subject_idx ON favorites (favoritable_type, favoritable_id);

-- One conversation per order, plus one direct conversation per pair of actors (pair_key = "smallerId:largerId").
CREATE TABLE conversations (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id uuid REFERENCES orders(id),
  pair_key text,
  last_message_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT conversations_order_unique UNIQUE (order_id),
  CONSTRAINT conversations_pair_key_unique UNIQUE (pair_key),
  CONSTRAINT conversations_order_xor_pair CHECK ((order_id IS NULL) <> (pair_key IS NULL)),
  CONSTRAINT conversations_pair_key_format CHECK (
    pair_key IS NULL OR (
      pair_key ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
      AND split_part(pair_key, ':', 1) < split_part(pair_key, ':', 2)))
);

CREATE TABLE conversation_participants (
  conversation_id uuid NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
  actor_id uuid NOT NULL REFERENCES actors(id),
  last_read_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (conversation_id, actor_id)
);
CREATE INDEX conversation_participants_actor_idx ON conversation_participants (actor_id);

CREATE TABLE messages (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  conversation_id uuid NOT NULL,
  sender_actor_id uuid NOT NULL,
  sender_user_id uuid REFERENCES users(id),
  kind text NOT NULL DEFAULT 'text',
  body text,
  created_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT messages_kind_valid CHECK (kind IN ('text','media')),
  CONSTRAINT messages_text_has_body CHECK (kind <> 'text' OR btrim(coalesce(body, '')) <> ''),
  -- only participants of the conversation can send
  CONSTRAINT messages_sender_is_participant FOREIGN KEY (conversation_id, sender_actor_id)
    REFERENCES conversation_participants (conversation_id, actor_id)
);
CREATE INDEX messages_conversation_idx ON messages (conversation_id, created_at DESC, id DESC);

CREATE TABLE notifications (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  type text NOT NULL,
  dedupe_key text NOT NULL,
  data jsonb NOT NULL DEFAULT '{}'::jsonb,
  read_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  -- the same event never notifies the same user twice
  CONSTRAINT notifications_dedupe_unique UNIQUE (user_id, dedupe_key),
  CONSTRAINT notifications_type_not_blank CHECK (btrim(type) <> '')
);
CREATE INDEX notifications_unread_idx ON notifications (user_id, created_at DESC) WHERE read_at IS NULL;

-- Participants must be the two sides of the order, or the two actors encoded in pair_key.
CREATE OR REPLACE FUNCTION conversation_participants_check() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE conv conversations%ROWTYPE;
BEGIN
  SELECT * INTO conv FROM conversations WHERE id = NEW.conversation_id;
  IF conv.order_id IS NOT NULL THEN
    IF NOT EXISTS (SELECT 1 FROM orders WHERE id = conv.order_id
                    AND NEW.actor_id IN (customer_actor_id, provider_actor_id)) THEN
      RAISE EXCEPTION 'only the customer and provider can join an order conversation' USING ERRCODE = 'check_violation';
    END IF;
  ELSIF NEW.actor_id::text NOT IN (split_part(conv.pair_key, ':', 1), split_part(conv.pair_key, ':', 2)) THEN
    RAISE EXCEPTION 'actor is not part of this conversation pair' USING ERRCODE = 'check_violation';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER conversation_participants_check BEFORE INSERT ON conversation_participants
  FOR EACH ROW EXECUTE FUNCTION conversation_participants_check();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS notifications, messages, conversation_participants, conversations, favorites, reports CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS conversation_participants_check() CASCADE');
    }
};
