<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;

class SchemaTest extends DatabaseTestCase
{
    private const MVP_TABLES = [
        'users', 'otp_codes', 'actors', 'actor_members', 'actor_invitations', 'verification_requests', 'audit_logs',
        'categories', 'provinces', 'cities', 'actor_service_areas', 'services',
        'media',
        'portfolio_items', 'needs', 'offers', 'orders', 'quotes', 'order_events', 'reviews', 'actor_stats',
        'reports', 'favorites', 'conversations', 'conversation_participants', 'messages', 'notifications',
        'personal_access_tokens', 'jobs', 'failed_jobs',
    ];

    private const PHASE_TWO_TABLES = [
        'posts', 'post_comments', 'contracts', 'disputes', 'blocks', 'user_devices', 'skills', 'actor_skills',
        'service_area_overrides', 'notification_preferences',
    ];

    public function test_exactly_the_thirty_mvp_tables_exist(): void
    {
        $tables = DB::table('information_schema.tables')->where('table_schema', 'public')->pluck('table_name')->all();

        foreach (self::MVP_TABLES as $table) {
            $this->assertContains($table, $tables, "$table is missing");
        }
        $this->assertCount(30, self::MVP_TABLES);
    }

    public function test_phase_two_tables_are_not_built_yet(): void
    {
        $tables = DB::table('information_schema.tables')->where('table_schema', 'public')->pluck('table_name')->all();

        $this->assertSame([], array_values(array_intersect(self::PHASE_TWO_TABLES, $tables)));
    }

    public function test_every_domain_table_uses_a_uuid_primary_key(): void
    {
        $system = ['personal_access_tokens', 'jobs', 'failed_jobs', 'actor_members', 'actor_service_areas', 'favorites',
            'conversation_participants', 'actor_stats'];

        foreach (array_diff(self::MVP_TABLES, $system) as $table) {
            $type = DB::table('information_schema.columns')
                ->where(['table_schema' => 'public', 'table_name' => $table, 'column_name' => 'id'])
                ->value('data_type');
            $this->assertSame('uuid', $type, "$table.id must be a uuid");
        }
    }

    public function test_money_columns_are_numeric_14_2(): void
    {
        $columns = [
            ['services', 'price_amount'], ['offers', 'amount'], ['orders', 'agreed_amount'], ['quotes', 'amount'],
        ];
        foreach ($columns as [$table, $column]) {
            $row = DB::table('information_schema.columns')
                ->where(['table_schema' => 'public', 'table_name' => $table, 'column_name' => $column])->first();
            $this->assertSame('numeric', $row->data_type);
            $this->assertEquals(14, $row->numeric_precision, "$table.$column precision");
            $this->assertEquals(2, $row->numeric_scale, "$table.$column scale");
        }
    }

    public function test_times_are_stored_with_time_zone(): void
    {
        $naive = DB::table('information_schema.columns')
            ->where('table_schema', 'public')->where('data_type', 'timestamp without time zone')->pluck('table_name', 'column_name');

        $this->assertCount(0, $naive, 'every timestamp must be timestamptz (UTC)');
    }

    public function test_search_indexes_exist(): void
    {
        $indexes = DB::table('pg_indexes')->where('schemaname', 'public')->pluck('indexdef', 'indexname');

        $this->assertStringContainsString('gin_trgm_ops', $indexes['actors_search_text_trgm']);
        $this->assertStringContainsString('gin_trgm_ops', $indexes['services_search_text_trgm']);
        $this->assertStringContainsString('USING gin (aliases)', $indexes['categories_aliases_gin']);
    }

    public function test_partial_unique_indexes_from_the_spec_exist(): void
    {
        $indexes = DB::table('pg_indexes')->where('schemaname', 'public')->pluck('indexdef', 'indexname');

        foreach (['one_primary_owner', 'one_pending_invitation', 'one_pending_quote', 'one_single_media',
            'one_pending_verification', 'one_open_report_per_user'] as $name) {
            $this->assertArrayHasKey($name, $indexes);
            $this->assertStringContainsString('UNIQUE', $indexes[$name]);
            $this->assertStringContainsString('WHERE', $indexes[$name], "$name must be a partial index");
        }
    }
}
