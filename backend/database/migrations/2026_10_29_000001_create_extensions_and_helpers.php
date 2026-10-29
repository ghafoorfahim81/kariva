<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // Shared trigger for append-only tables (order_events, audit_logs).
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION kariva_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION '% is append-only', TG_TABLE_NAME USING ERRCODE = 'check_violation';
END $$;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS kariva_append_only() CASCADE');
    }
};
