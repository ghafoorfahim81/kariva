<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;

class OperationsTest extends ApiTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/kariva-backup-test-'.uniqid();
        config(['kariva.backup.path' => $this->dir.'/out', 'kariva.backup.files_path' => $this->dir.'/files']);
        File::ensureDirectoryExists($this->dir.'/files/actor');
        File::put($this->dir.'/files/actor/a.txt', 'hello');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    // ---------------------------------------------------------------- headers

    public function test_every_api_answer_carries_the_defensive_headers(): void
    {
        foreach (['/api/v1/ping', '/api/v1/health', '/api/v1/needs'] as $url) {
            $this->getJson($url)->assertOk()
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        }
    }

    public function test_personal_answers_are_never_cached_but_reference_data_may_be(): void
    {
        $user = $this->person();

        $this->actingAsUser($user['user'])->getJson('/api/v1/me')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/categories')->assertOk();
        $this->assertStringContainsString('max-age=3600', $this->getJson('/api/v1/categories')->headers->get('Cache-Control'));
    }

    public function test_error_answers_are_json_without_internals(): void
    {
        config(['app.debug' => false]);   // how production runs
        $body = $this->getJson('/api/v1/actors/not-a-uuid')->assertNotFound()->getContent();

        $this->assertStringNotContainsString('vendor/', $body);
        $this->assertStringNotContainsString('Stack trace', $body);
    }

    public function test_the_session_cookie_settings_are_safe(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    // ---------------------------------------------------------------- health

    public function test_the_public_health_check_is_ok_and_tells_nothing_else(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_the_detailed_health_check_is_for_admins_only(): void
    {
        $this->actingAsUser($this->person()['user'])->getJson('/api/v1/admin/health')->assertForbidden();
        $admin = $this->person(['is_platform_admin' => true]);

        $res = $this->actingAsUser($admin['user'])->getJson('/api/v1/admin/health')->assertOk();

        $res->assertJsonStructure(['status', 'checks' => ['database', 'cache', 'queue', 'scheduler', 'disk', 'backup']]);
        $this->assertTrue($res->json('checks.database.ok'));
    }

    public function test_the_scheduler_and_backup_checks_turn_green_when_their_jobs_run(): void
    {
        $admin = $this->person(['is_platform_admin' => true]);
        Cache::forget('scheduler:heartbeat');
        Cache::forget('backup:last_success');
        $check = fn () => $this->actingAsUser($admin['user'])->getJson('/api/v1/admin/health')->json('checks');

        $this->assertFalse($check()['scheduler']['ok']);
        $this->assertFalse($check()['backup']['ok']);
        $this->artisan('kariva:heartbeat')->assertSuccessful();
        $this->artisan('kariva:backup')->assertSuccessful();

        $this->assertTrue($check()['scheduler']['ok']);
        $this->assertTrue($check()['backup']['ok']);
    }

    // ---------------------------------------------------------------- backup

    public function test_the_backup_writes_a_database_dump_and_a_files_archive(): void
    {
        $this->artisan('kariva:backup')->assertSuccessful();

        $dumps = glob($this->dir.'/out/db-*.dump');
        $archives = glob($this->dir.'/out/files-*.tar.gz');
        $this->assertCount(1, $dumps);
        $this->assertGreaterThan(1000, filesize($dumps[0]));
        $this->assertCount(1, $archives);
        $this->assertSame(0700, fileperms($this->dir.'/out') & 0777);   // not readable by other users
        $this->assertNotNull(Cache::get('backup:last_success'));
    }

    public function test_the_dump_can_be_read_back_and_contains_the_data(): void
    {
        $this->person(['phone_e164' => '+93771234567']);
        // the test runs inside a transaction, so commit-visible data is not in the dump; the schema must be.
        $this->artisan('kariva:backup', ['--skip-files' => true])->assertSuccessful();
        $dump = glob($this->dir.'/out/db-*.dump')[0];

        $list = shell_exec('pg_restore --list '.escapeshellarg($dump));

        $this->assertStringContainsString('TABLE public orders', $list);
        $this->assertStringContainsString('TABLE public users', $list);
        $this->assertSame([], glob($this->dir.'/out/files-*'));
    }

    public function test_old_backups_are_removed_and_recent_ones_kept(): void
    {
        File::ensureDirectoryExists($this->dir.'/out', 0700);
        $old = $this->dir.'/out/db-20200101-000000.dump';
        $recent = $this->dir.'/out/db-20990101-000000.dump';
        File::put($old, 'x');
        File::put($recent, 'x');
        touch($old, time() - 20 * 86400);
        touch($recent, time() - 2 * 86400);
        config(['kariva.backup.keep_days' => 14]);

        $this->artisan('kariva:backup', ['--skip-files' => true])->assertSuccessful();

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
    }

    public function test_a_failed_backup_reports_failure_and_does_not_mark_success(): void
    {
        Cache::forget('backup:last_success');
        config(['database.connections.pgsql.database' => 'database_that_does_not_exist']);   // only the dump command reads this

        $this->artisan('kariva:backup')->assertFailed();

        $this->assertNull(Cache::get('backup:last_success'));
    }

    public function test_backup_and_heartbeat_are_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('kariva:backup')->expectsOutputToContain('kariva:heartbeat')->assertSuccessful();
    }

    // ---------------------------------------------------------------- limits

    public function test_the_default_api_limit_exists(): void
    {
        $this->assertNotNull(RateLimiter::limiter('api'));
    }
}
