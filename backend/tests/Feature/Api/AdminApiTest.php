<?php

namespace Tests\Feature\Api;

use App\Jobs\PurgeMedia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminApiTest extends ApiTestCase
{
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->admin = $this->person(['is_platform_admin' => true]);
    }

    private function asAdmin(): static
    {
        return $this->actingAsUser($this->admin['user'])->withoutHeader('X-Actor-Id');
    }

    /** A pending verification with one document photo, made through the public API like a real user. */
    private function pendingVerification(?array $who = null): array
    {
        $who ??= $this->person();
        $id = $this->actingAsUser($who['user'], $who['actor'])->postJson('/api/v1/verification')->assertCreated()->json('id');
        $media = $this->actingAsUser($who['user'])->post('/api/v1/media', ['model_type' => 'verification_request', 'model_id' => $id, 'collection' => 'document', 'file' => UploadedFile::fake()->image('id.jpg')->size(200)], ['Accept' => 'application/json'])->json('id');

        return ['who' => $who, 'id' => $id, 'media' => $media];
    }

    // ---------------------------------------------------------------- access

    public function test_only_platform_admins_enter_the_panel(): void
    {
        $user = $this->person();

        $this->getJson('/api/v1/admin/overview')->assertUnauthorized();
        $this->actingAsUser($user['user'])->getJson('/api/v1/admin/overview')->assertForbidden()->assertJsonPath('code', 'admin.forbidden');
        $this->asAdmin()->getJson('/api/v1/admin/overview')->assertOk();
    }

    public function test_a_suspended_admin_has_no_access(): void
    {
        DB::table('users')->where('id', $this->admin['user'])->update(['status' => 'suspended']);

        $this->asAdmin()->getJson('/api/v1/admin/overview')->assertForbidden();
    }

    public function test_admin_rights_cannot_be_obtained_through_the_api(): void
    {
        $user = $this->person();

        $this->actingAsUser($user['user'])->patchJson('/api/v1/actors/'.$user['actor'], ['is_platform_admin' => true])->assertOk();
        $this->assertFalse((bool) DB::table('users')->where('id', $user['user'])->value('is_platform_admin'));
    }

    public function test_me_tells_the_client_whether_to_show_the_admin_entry(): void
    {
        $this->asAdmin()->getJson('/api/v1/me')->assertJsonPath('is_admin', true);
        $this->actingAsUser($this->person()['user'])->getJson('/api/v1/me')->assertJsonPath('is_admin', false);
    }

    public function test_the_make_admin_command(): void
    {
        $user = $this->person(['phone_e164' => '+93771234567']);

        $this->artisan('kariva:make-admin', ['phone' => '0771234567'])->assertSuccessful();
        $this->assertTrue((bool) DB::table('users')->where('id', $user['user'])->value('is_platform_admin'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.granted']);

        $this->artisan('kariva:make-admin', ['phone' => '0771234567', '--revoke' => true])->assertSuccessful();
        $this->assertFalse((bool) DB::table('users')->where('id', $user['user'])->value('is_platform_admin'));
        $this->artisan('kariva:make-admin', ['phone' => '0779999999'])->assertFailed();
        $this->artisan('kariva:make-admin', ['phone' => 'abc'])->assertFailed();
    }

    // ---------------------------------------------------------------- verification

    public function test_the_queue_lists_pending_requests_oldest_first_with_documents(): void
    {
        $first = $this->pendingVerification();
        $this->travel(1)->minute();
        $second = $this->pendingVerification();

        $res = $this->asAdmin()->getJson('/api/v1/admin/verifications')->assertOk();

        $this->assertSame([$first['id'], $second['id']], array_column($res->json('data'), 'id'));
        $this->assertSame(1, count($res->json('data.0.documents')));
        $this->assertSame($first['who']['actor'], $res->json('data.0.actor.id'));
        $this->assertNotNull($res->json('data.0.requester_phone'));
    }

    public function test_the_admin_can_see_a_document_and_it_is_logged(): void
    {
        $v = $this->pendingVerification();

        $this->asAdmin()->get('/api/v1/admin/documents/'.$v['media'])->assertOk()->assertHeader('Cache-Control')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.document_viewed', 'user_id' => $this->admin['user'], 'subject_id' => $v['id']]);
    }

    public function test_documents_are_not_reachable_without_admin_rights_or_through_any_public_path(): void
    {
        $v = $this->pendingVerification();

        $this->actingAsUser($v['who']['user'])->get('/api/v1/admin/documents/'.$v['media'])->assertForbidden();
        $this->getJson("/api/v1/actors/{$v['who']['actor']}")->assertOk();
        $path = DB::table('media')->where('id', $v['media'])->value('path');
        Storage::disk('public')->assertMissing($path);
        $this->asAdmin()->get('/api/v1/admin/documents/'.Str::uuid())->assertNotFound();
    }

    public function test_approving_sets_the_badge_notifies_and_schedules_the_purge(): void
    {
        $v = $this->pendingVerification();

        $this->asAdmin()->postJson("/api/v1/admin/verifications/{$v['id']}/approve", ['note' => 'مدرک واضح بود'])->assertOk()->assertJsonPath('status', 'approved');

        $this->assertNotNull(DB::table('actors')->where('id', $v['who']['actor'])->value('verified_at'));
        $this->getJson("/api/v1/actors/{$v['who']['actor']}")->assertJsonPath('verified', true);
        $row = DB::table('verification_requests')->where('id', $v['id'])->first();
        $this->assertSame($this->admin['user'], $row->reviewed_by);
        $this->assertNotNull($row->files_purge_at);
        $this->assertEqualsWithDelta(30 * 86400, strtotime($row->files_purge_at) - time(), 120);
        $this->assertNotNull(DB::table('media')->where('id', $v['media'])->value('purge_at'));
        $this->assertDatabaseHas('notifications', ['user_id' => $v['who']['user'], 'type' => 'verification.approved']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.verification_approved', 'actor_id' => $v['who']['actor']]);
    }

    public function test_rejecting_needs_an_explanation_and_leaves_no_badge(): void
    {
        $v = $this->pendingVerification();

        $this->asAdmin()->postJson("/api/v1/admin/verifications/{$v['id']}/reject", [])->assertUnprocessable()->assertJsonPath('code', 'verification.note_required');
        $this->asAdmin()->postJson("/api/v1/admin/verifications/{$v['id']}/reject", ['note' => 'عکس تار است'])->assertOk()->assertJsonPath('status', 'rejected');

        $this->assertNull(DB::table('actors')->where('id', $v['who']['actor'])->value('verified_at'));
        $this->actingAsUser($v['who']['user'], $v['who']['actor'])->getJson('/api/v1/verification')->assertJsonPath('request.status', 'rejected')->assertJsonPath('request.note', 'عکس تار است');
        $this->assertDatabaseHas('notifications', ['user_id' => $v['who']['user'], 'type' => 'verification.rejected']);
    }

    public function test_a_request_is_decided_once_and_a_rejected_actor_can_try_again(): void
    {
        $v = $this->pendingVerification();
        $this->asAdmin()->postJson("/api/v1/admin/verifications/{$v['id']}/reject", ['note' => 'ناقص'])->assertOk();

        $this->asAdmin()->postJson("/api/v1/admin/verifications/{$v['id']}/approve")->assertStatus(409)->assertJsonPath('code', 'verification.not_pending');
        $this->actingAsUser($v['who']['user'], $v['who']['actor'])->postJson('/api/v1/verification')->assertCreated();
    }

    public function test_the_nightly_job_deletes_decided_documents_after_the_purge_date(): void
    {
        $v = $this->pendingVerification();
        $path = DB::table('media')->where('id', $v['media'])->value('path');
        $this->asAdmin()->postJson("/api/v1/admin/verifications/{$v['id']}/approve")->assertOk();

        (new PurgeMedia)->handle();
        $this->assertDatabaseHas('media', ['id' => $v['media']]);   // not yet

        $this->travel(31)->days();
        (new PurgeMedia)->handle();
        $this->assertDatabaseMissing('media', ['id' => $v['media']]);
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseHas('verification_requests', ['id' => $v['id'], 'status' => 'approved']);   // the decision itself stays
    }

    public function test_other_statuses_can_be_listed(): void
    {
        $v = $this->pendingVerification();
        $this->asAdmin()->postJson("/api/v1/admin/verifications/{$v['id']}/approve")->assertOk();

        $this->assertCount(0, $this->asAdmin()->getJson('/api/v1/admin/verifications')->json('data'));
        $this->assertCount(1, $this->asAdmin()->getJson('/api/v1/admin/verifications?status=approved')->json('data'));
        $this->asAdmin()->getJson('/api/v1/admin/verifications?status=weird')->assertUnprocessable();
    }

    // ---------------------------------------------------------------- reports

    private function report(string $type, string $id, string $reason = 'اسپم'): string
    {
        $user = $this->person();

        return $this->actingAsUser($user['user'])->postJson('/api/v1/reports', ['type' => $type, 'id' => $id, 'reason' => $reason])->assertCreated()->json('id');
    }

    public function test_open_reports_show_the_subject(): void
    {
        $page = $this->business(null, ['display_name' => 'صفحه مشکوک', 'bio' => 'متن مشکوک']);
        $this->report('actor', $page['actor']);

        $row = $this->asAdmin()->getJson('/api/v1/admin/reports')->assertOk()->json('data.0');

        $this->assertSame('actor', $row['type']);
        $this->assertSame('صفحه مشکوک', $row['subject']['title']);
        $this->assertSame('متن مشکوک', $row['subject']['text']);
        $this->assertNotNull($row['reporter_phone']);
    }

    public function test_dismissing_and_resolving_close_the_report_and_tell_the_reporter(): void
    {
        $page = $this->business();
        $a = $this->report('actor', $page['actor']);
        $b = $this->report('actor', $page['actor'], 'تقلب');

        $this->asAdmin()->postJson("/api/v1/admin/reports/$a/dismiss", ['note' => 'مشکلی نیست'])->assertOk()->assertJsonPath('status', 'dismissed');
        $this->asAdmin()->postJson("/api/v1/admin/reports/$b/resolve")->assertOk()->assertJsonPath('status', 'resolved');

        $this->assertSame(2, DB::table('reports')->whereNotNull('resolved_by')->count());
        $this->assertDatabaseHas('notifications', ['type' => 'report.dismissed']);
        $this->assertDatabaseHas('notifications', ['type' => 'report.resolved']);
        $this->asAdmin()->postJson("/api/v1/admin/reports/$a/resolve")->assertStatus(409)->assertJsonPath('code', 'report.not_open');
        $this->assertCount(0, $this->asAdmin()->getJson('/api/v1/admin/reports')->json('data'));
        $this->assertCount(1, $this->asAdmin()->getJson('/api/v1/admin/reports?status=dismissed')->json('data'));
        $this->assertCount(1, $this->asAdmin()->getJson('/api/v1/admin/reports?status=resolved')->json('data'));
    }

    public function test_resolving_can_suspend_the_reported_actor_in_the_same_step(): void
    {
        $page = $this->business();
        $report = $this->report('actor', $page['actor']);

        $this->asAdmin()->postJson("/api/v1/admin/reports/$report/resolve", ['action' => 'suspend_actor', 'note' => 'فریب'])->assertOk();

        $this->assertDatabaseHas('actors', ['id' => $page['actor'], 'status' => 'suspended']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.actor_suspended', 'actor_id' => $page['actor']]);
        $this->getJson("/api/v1/actors/{$page['actor']}")->assertNotFound();   // hidden from the public
    }

    public function test_resolving_can_delete_a_reported_review_and_the_rating_is_recomputed(): void
    {
        $customer = $this->person();
        $provider = $this->business();
        $order = $this->order($customer['actor'], $provider['actor']);
        $this->advance($order, 'completed');
        $review = $this->actingAsUser($customer['user'], $customer['actor'])->postJson("/api/v1/orders/$order/review", ['stars' => 1, 'body' => 'توهین'])->assertCreated()->json('id');
        $this->assertDatabaseHas('actor_stats', ['actor_id' => $provider['actor'], 'rating_count' => 1]);
        $report = $this->report('review', $review);

        $this->asAdmin()->postJson("/api/v1/admin/reports/$report/resolve", ['action' => 'delete_review'])->assertOk();

        $this->assertDatabaseMissing('reviews', ['id' => $review]);
        $this->assertDatabaseHas('actor_stats', ['actor_id' => $provider['actor'], 'rating_count' => 0, 'rating_avg' => null]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.review_deleted']);
    }

    public function test_resolving_can_archive_a_reported_service(): void
    {
        $page = $this->business();
        $service = $this->service($page['actor']);
        $report = $this->report('service', $service);

        $this->asAdmin()->postJson("/api/v1/admin/reports/$report/resolve", ['action' => 'archive_service'])->assertOk();

        $this->assertDatabaseHas('services', ['id' => $service, 'status' => 'archived']);
    }

    public function test_an_action_must_fit_the_reported_subject(): void
    {
        $page = $this->business();
        $report = $this->report('actor', $page['actor']);

        $this->asAdmin()->postJson("/api/v1/admin/reports/$report/resolve", ['action' => 'delete_review'])->assertUnprocessable()->assertJsonPath('code', 'admin.action_does_not_fit');
        $this->assertDatabaseHas('reports', ['id' => $report, 'status' => 'open']);   // nothing was half-done
        $this->asAdmin()->postJson("/api/v1/admin/reports/$report/resolve", ['action' => 'nuke'])->assertUnprocessable();
    }

    // ---------------------------------------------------------------- suspension

    public function test_suspending_a_user_signs_them_out_and_blocks_login(): void
    {
        $user = $this->person(['phone_e164' => '+93771234567']);
        $this->actingAsUser($user['user'])->getJson('/api/v1/me')->assertOk();

        $this->asAdmin()->postJson("/api/v1/admin/users/{$user['user']}/suspend", ['reason' => 'تقلب'])->assertOk()->assertJsonPath('status', 'suspended');

        $this->actingAsUser($user['user'])->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('code', 'auth.account_unavailable');
        $this->postJson('/api/v1/auth/otp', ['phone' => '0771234567'])->assertNoContent();
        $this->postJson('/api/v1/auth/verify', ['phone' => '0771234567', 'code' => $this->otp->lastCode()])->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.user_suspended', 'subject_id' => $user['user']]);

        $this->asAdmin()->postJson("/api/v1/admin/users/{$user['user']}/unsuspend")->assertOk()->assertJsonPath('status', 'active');
        $this->actingAsUser($user['user'])->getJson('/api/v1/me')->assertOk();
    }

    public function test_tokens_of_a_suspended_user_are_removed(): void
    {
        $user = $this->person();
        User::find($user['user'])->createToken('mobile');
        $this->assertSame(1, DB::table('personal_access_tokens')->count());

        $this->asAdmin()->postJson("/api/v1/admin/users/{$user['user']}/suspend")->assertOk();

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_an_admin_cannot_suspend_themselves(): void
    {
        $this->asAdmin()->postJson("/api/v1/admin/users/{$this->admin['user']}/suspend")->assertUnprocessable()->assertJsonPath('code', 'admin.cannot_suspend_self');
    }

    public function test_suspending_a_page_stops_new_orders_only_and_can_be_undone(): void
    {
        $customer = $this->person();
        $page = $this->business();
        $existing = $this->order($customer['actor'], $page['actor']);

        $this->asAdmin()->postJson("/api/v1/admin/actors/{$page['actor']}/suspend", ['reason' => 'بررسی'])->assertOk()->assertJsonPath('status', 'suspended');

        $this->actingAsUser($customer['user'], $customer['actor'])->postJson('/api/v1/orders', ['provider_actor_id' => $page['actor'], 'title' => 'کار تازه'])->assertUnprocessable();
        $this->actingAsUser($page['user'], $page['actor'])->postJson("/api/v1/orders/$existing/quote", ['amount' => 5, 'currency' => 'AFN', 'price_unit' => 'hour'])->assertOk();
        $this->asAdmin()->postJson("/api/v1/admin/actors/{$page['actor']}/unsuspend")->assertOk()->assertJsonPath('status', 'active');
        $this->getJson("/api/v1/actors/{$page['actor']}")->assertOk();
    }

    public function test_deleted_pages_cannot_be_reactivated(): void
    {
        $page = $this->business();
        DB::table('actors')->where('id', $page['actor'])->update(['deletion_requested_by' => $page['user'], 'deletion_requested_at' => now(), 'deletion_confirmed_by' => $page['user'], 'status' => 'deleted']);

        $this->asAdmin()->postJson("/api/v1/admin/actors/{$page['actor']}/unsuspend")->assertStatus(409)->assertJsonPath('code', 'admin.actor_deleted');
        $this->assertDatabaseHas('actors', ['id' => $page['actor'], 'status' => 'deleted']);
    }

    // ---------------------------------------------------------------- search, overview, audit

    public function test_admins_search_people_and_pages_by_name_or_phone(): void
    {
        $this->person(['phone_e164' => '+93771234567'], ['display_name' => 'احمد']);
        $this->business(null, ['display_name' => 'شرکت نور']);

        $byName = $this->asAdmin()->getJson('/api/v1/admin/actors?q='.urlencode('نور'))->assertOk()->json('data');
        $byPhone = $this->asAdmin()->getJson('/api/v1/admin/actors?q=0771234567')->json('data');

        $this->assertSame(['شرکت نور'], array_column($byName, 'display_name'));
        $this->assertSame(['احمد'], array_column($byPhone, 'display_name'));
        $this->assertSame('+93771234567', $byPhone[0]['phone']);
        $this->assertCount(1, $this->asAdmin()->getJson('/api/v1/admin/actors?type=business&status=active')->json('data'));
        $this->assertStringNotContainsString('+93771234567', $this->getJson('/api/v1/search/actors')->getContent());   // phones stay private for everyone else
    }

    public function test_the_overview_counts_what_needs_attention(): void
    {
        $this->pendingVerification();
        $this->report('actor', $this->business()['actor']);

        $this->asAdmin()->getJson('/api/v1/admin/overview')->assertOk()->assertJsonPath('pending_verifications', 1)->assertJsonPath('open_reports', 1)->assertJsonStructure(['users', 'actors' => ['person', 'business'], 'orders_last_7_days', 'completed_orders', 'open_needs']);
    }

    public function test_the_audit_log_is_readable_filterable_and_paged(): void
    {
        $v = $this->pendingVerification();
        $this->asAdmin()->postJson("/api/v1/admin/verifications/{$v['id']}/approve")->assertOk();
        $this->asAdmin()->postJson('/api/v1/admin/actors/'.$this->business()['actor'].'/suspend')->assertOk();

        $all = $this->asAdmin()->getJson('/api/v1/admin/audit-logs?limit=1')->assertOk();
        $this->assertNotNull($all->json('next_cursor'));
        $this->assertSame('admin.verification_approved', $this->asAdmin()->getJson('/api/v1/admin/audit-logs?action=admin.verification')->json('data.0.action'));
        $this->asAdmin()->getJson('/api/v1/admin/audit-logs?cursor=bad')->assertUnprocessable()->assertJsonPath('code', 'admin.bad_cursor');
    }
}
