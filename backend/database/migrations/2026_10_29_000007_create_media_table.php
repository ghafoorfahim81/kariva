<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // One polymorphic table for every upload. model_type holds the short morphMap name, never a PHP class.
        DB::unprepared(<<<'SQL'
CREATE TABLE media (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  model_type text NOT NULL,
  model_id uuid NOT NULL,
  collection text NOT NULL,
  visibility text NOT NULL,
  disk text NOT NULL,
  path text NOT NULL,
  mime text NOT NULL,
  size_bytes bigint NOT NULL,
  scan_status text NOT NULL DEFAULT 'pending',
  sort_order int NOT NULL DEFAULT 0,
  purge_at timestamptz,
  created_by uuid REFERENCES users(id),
  created_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT media_model_type_valid CHECK (model_type IN ('actor','service','portfolio_item','order','message','verification_request')),
  CONSTRAINT media_visibility_valid CHECK (visibility IN ('public','private')),
  CONSTRAINT media_scan_status_valid CHECK (scan_status IN ('pending','clean','infected')),
  CONSTRAINT media_size_positive CHECK (size_bytes > 0),
  -- visibility is derived from the collection and cannot be picked by the user
  CONSTRAINT media_collection_valid CHECK (
       (model_type = 'actor'                AND collection IN ('avatar','cover')        AND visibility = 'public')
    OR (model_type = 'service'              AND collection = 'gallery'                  AND visibility = 'public')
    OR (model_type = 'portfolio_item'       AND collection = 'images'                   AND visibility = 'public')
    OR (model_type = 'order'                AND collection IN ('attachments','voice')   AND visibility = 'private')
    OR (model_type = 'message'              AND collection IN ('attachment','voice')    AND visibility = 'private')
    OR (model_type = 'verification_request' AND collection = 'document'                 AND visibility = 'private')
  )
);
CREATE UNIQUE INDEX one_single_media ON media (model_type, model_id, collection) WHERE collection IN ('avatar','cover');
CREATE INDEX media_model ON media (model_type, model_id, collection, sort_order);
CREATE INDEX media_orphan_check ON media (model_type, model_id);
CREATE INDEX media_purge_idx ON media (purge_at) WHERE purge_at IS NOT NULL;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS media CASCADE');
    }
};
