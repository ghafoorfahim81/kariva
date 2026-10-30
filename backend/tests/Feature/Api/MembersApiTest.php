<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MembersApiTest extends ApiTestCase
{
    private function phoneOf(string $userId): string
    {
        return DB::table('users')->where('id', $userId)->value('phone_e164');
    }

    private function invite(array $page, string $asUser, array $body)
    {
        return $this->actingAsUser($asUser)->postJson("/api/v1/actors/{$page['actor']}/invitations", $body);
    }

    // ---------------------------------------------------------------- listing

    public function test_members_see_the_team_but_only_managers_see_phone_numbers(): void
    {
        $page = $this->business();
        $admin = $this->member($page['actor'], 'admin');
        $viewer = $this->member($page['actor'], 'viewer');
        $url = "/api/v1/actors/{$page['actor']}/members";

        $asAdmin = $this->actingAsUser($admin)->getJson($url)->assertOk()->assertJsonCount(3, 'data');
        $this->assertTrue($asAdmin->json('data.0.is_primary'));
        $this->assertNotNull($asAdmin->json('data.0.phone'));

        $asViewer = $this->actingAsUser($viewer)->getJson($url)->assertOk();
        $this->assertNull($asViewer->json('data.0.phone'));
        $this->actingAsUser($this->person()['user'])->getJson($url)->assertForbidden();
    }

    // ---------------------------------------------------------------- invitations

    public function test_an_owner_invites_by_phone_and_the_invitee_accepts_and_joins(): void
    {
        $page = $this->business();
        $invitee = $this->person();

        $id = $this->invite($page, $page['user'], ['phone' => $this->phoneOf($invitee['user']), 'role' => 'editor'])->assertCreated()->json('id');

        $this->actingAsUser($invitee['user'])->getJson('/api/v1/invitations')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.actor.id', $page['actor']);
        $this->actingAsUser($invitee['user'])->postJson("/api/v1/invitations/$id/respond", ['accept' => true])->assertOk()->assertJsonPath('status', 'accepted');

        $this->assertDatabaseHas('actor_members', ['actor_id' => $page['actor'], 'user_id' => $invitee['user'], 'role' => 'editor', 'is_primary' => false]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'invitation.accepted', 'actor_id' => $page['actor']]);
    }

    public function test_declining_adds_nobody(): void
    {
        $page = $this->business();
        $invitee = $this->person();
        $id = $this->invite($page, $page['user'], ['phone' => $this->phoneOf($invitee['user']), 'role' => 'viewer'])->json('id');

        $this->actingAsUser($invitee['user'])->postJson("/api/v1/invitations/$id/respond", ['accept' => false])->assertOk()->assertJsonPath('status', 'declined');
        $this->assertDatabaseMissing('actor_members', ['actor_id' => $page['actor'], 'user_id' => $invitee['user']]);
    }

    public function test_an_invitation_to_an_unregistered_number_works_after_that_person_signs_up(): void
    {
        $page = $this->business();
        $this->invite($page, $page['user'], ['phone' => '0701234567', 'role' => 'editor'])->assertCreated();

        $this->postJson('/api/v1/auth/otp', ['phone' => '0701234567']);
        $login = $this->postJson('/api/v1/auth/verify', ['phone' => '0701234567', 'code' => $this->otp->lastCode()])->assertOk();
        $newUser = $login->json('user.id');

        $this->actingAsUser($newUser)->getJson('/api/v1/invitations')->assertJsonCount(1, 'data');
    }

    public function test_only_the_invited_phone_can_answer(): void
    {
        $page = $this->business();
        $invitee = $this->person();
        $id = $this->invite($page, $page['user'], ['phone' => $this->phoneOf($invitee['user']), 'role' => 'editor'])->json('id');

        $this->actingAsUser($this->person()['user'])->postJson("/api/v1/invitations/$id/respond", ['accept' => true])->assertNotFound();
        $this->actingAsUser($page['user'])->postJson("/api/v1/invitations/$id/respond", ['accept' => true])->assertNotFound();
    }

    public function test_an_invitation_is_answered_once(): void
    {
        $page = $this->business();
        $invitee = $this->person();
        $id = $this->invite($page, $page['user'], ['phone' => $this->phoneOf($invitee['user']), 'role' => 'editor'])->json('id');

        $this->actingAsUser($invitee['user'])->postJson("/api/v1/invitations/$id/respond", ['accept' => false])->assertOk();
        $this->actingAsUser($invitee['user'])->postJson("/api/v1/invitations/$id/respond", ['accept' => true])->assertStatus(409)->assertJsonPath('code', 'invitation.not_pending');
    }

    public function test_an_invitation_expires_after_seven_days(): void
    {
        $page = $this->business();
        $invitee = $this->person();
        $id = $this->invite($page, $page['user'], ['phone' => $this->phoneOf($invitee['user']), 'role' => 'editor'])->json('id');

        $this->travel(8)->days();

        $this->actingAsUser($invitee['user'])->getJson('/api/v1/invitations')->assertJsonCount(0, 'data');
        $this->actingAsUser($invitee['user'])->postJson("/api/v1/invitations/$id/respond", ['accept' => true])->assertStatus(410)->assertJsonPath('code', 'invitation.expired');
        $this->assertDatabaseHas('actor_invitations', ['id' => $id, 'status' => 'expired']);
        $this->assertDatabaseMissing('actor_members', ['actor_id' => $page['actor'], 'user_id' => $invitee['user']]);
    }

    public function test_an_expired_invitation_can_be_sent_again(): void
    {
        $page = $this->business();
        $phone = $this->phoneOf($this->person()['user']);
        $this->invite($page, $page['user'], ['phone' => $phone, 'role' => 'editor'])->assertCreated();
        $this->travel(8)->days();

        $this->invite($page, $page['user'], ['phone' => $phone, 'role' => 'editor'])->assertCreated();
    }

    public function test_a_second_pending_invitation_for_the_same_phone_is_refused(): void
    {
        $page = $this->business();
        $phone = $this->phoneOf($this->person()['user']);
        $this->invite($page, $page['user'], ['phone' => $phone, 'role' => 'editor'])->assertCreated();

        $this->invite($page, $page['user'], ['phone' => $phone, 'role' => 'viewer'])->assertStatus(409)->assertJsonPath('code', 'invitation.already_pending');
    }

    public function test_existing_members_and_yourself_cannot_be_invited(): void
    {
        $page = $this->business();
        $member = $this->member($page['actor'], 'viewer');

        $this->invite($page, $page['user'], ['phone' => $this->phoneOf($member), 'role' => 'editor'])->assertStatus(409)->assertJsonPath('code', 'invitation.already_member');
        $this->invite($page, $page['user'], ['phone' => $this->phoneOf($page['user']), 'role' => 'editor'])->assertUnprocessable();
    }

    public function test_admins_cannot_invite_owners_and_editors_cannot_invite_at_all(): void
    {
        $page = $this->business();
        $admin = $this->member($page['actor'], 'admin');
        $editor = $this->member($page['actor'], 'editor');

        $this->invite($page, $admin, ['phone' => $this->phoneOf($this->person()['user']), 'role' => 'owner'])->assertForbidden();
        $this->invite($page, $editor, ['phone' => $this->phoneOf($this->person()['user']), 'role' => 'viewer'])->assertForbidden();
        $this->invite($page, $this->person()['user'], ['phone' => $this->phoneOf($this->person()['user']), 'role' => 'viewer'])->assertForbidden();
        $this->invite($page, $page['user'], ['phone' => $this->phoneOf($this->person()['user']), 'role' => 'owner'])->assertCreated();
    }

    public function test_the_role_is_required_and_limited(): void
    {
        $page = $this->business();

        $this->invite($page, $page['user'], ['phone' => '0701234567'])->assertUnprocessable();
        $this->invite($page, $page['user'], ['phone' => '0701234567', 'role' => 'boss'])->assertUnprocessable();
        $this->invite($page, $page['user'], ['phone' => '12', 'role' => 'viewer'])->assertUnprocessable();
    }

    public function test_pending_invitations_can_be_listed_and_cancelled_by_managers(): void
    {
        $page = $this->business();
        $id = $this->invite($page, $page['user'], ['phone' => '0701234567', 'role' => 'viewer'])->json('id');
        $url = "/api/v1/actors/{$page['actor']}/invitations";

        $this->actingAsUser($page['user'])->getJson($url)->assertJsonCount(1, 'data')->assertJsonPath('data.0.phone', '+93701234567');
        $this->actingAsUser($this->member($page['actor'], 'viewer'))->getJson($url)->assertForbidden();
        $this->actingAsUser($page['user'])->deleteJson("$url/$id")->assertNoContent();
        $this->actingAsUser($page['user'])->getJson($url)->assertJsonCount(0, 'data');
    }

    // ---------------------------------------------------------------- primary ownership transfer

    public function test_primary_ownership_moves_by_invitation_and_acceptance(): void
    {
        $page = $this->business();
        $newPrimary = $this->person();
        $id = $this->invite($page, $page['user'], ['phone' => $this->phoneOf($newPrimary['user']), 'kind' => 'transfer_primary'])->assertCreated()->assertJsonPath('role', 'owner')->json('id');

        // nothing changes until the invitee accepts
        $this->assertDatabaseHas('actor_members', ['actor_id' => $page['actor'], 'user_id' => $page['user'], 'is_primary' => true]);

        $this->actingAsUser($newPrimary['user'])->postJson("/api/v1/invitations/$id/respond", ['accept' => true])->assertOk();

        $this->assertDatabaseHas('actor_members', ['actor_id' => $page['actor'], 'user_id' => $newPrimary['user'], 'role' => 'owner', 'is_primary' => true]);
        $this->assertDatabaseHas('actor_members', ['actor_id' => $page['actor'], 'user_id' => $page['user'], 'role' => 'owner', 'is_primary' => false]);
        $this->assertSame(1, DB::table('actor_members')->where('actor_id', $page['actor'])->where('is_primary', true)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'actor.primary_owner_transferred', 'actor_id' => $page['actor']]);
    }

    public function test_an_existing_co_owner_can_become_primary(): void
    {
        $page = $this->business();
        $co = $this->member($page['actor'], 'owner');
        $id = $this->invite($page, $page['user'], ['phone' => $this->phoneOf($co), 'kind' => 'transfer_primary'])->json('id');

        $this->actingAsUser($co)->postJson("/api/v1/invitations/$id/respond", ['accept' => true])->assertOk();

        $this->assertDatabaseHas('actor_members', ['actor_id' => $page['actor'], 'user_id' => $co, 'is_primary' => true]);
        $this->assertSame(2, DB::table('actor_members')->where('actor_id', $page['actor'])->count());
    }

    public function test_only_the_primary_owner_can_start_a_transfer(): void
    {
        $page = $this->business();
        $co = $this->member($page['actor'], 'owner');

        $this->invite($page, $co, ['phone' => '0701234567', 'kind' => 'transfer_primary'])->assertForbidden();
        $this->invite($page, $this->member($page['actor'], 'admin'), ['phone' => '0701234567', 'kind' => 'transfer_primary'])->assertForbidden();
    }

    public function test_a_transfer_fails_if_the_inviter_is_no_longer_primary(): void
    {
        $page = $this->business();
        $first = $this->person();
        $second = $this->person();
        $one = $this->invite($page, $page['user'], ['phone' => $this->phoneOf($first['user']), 'kind' => 'transfer_primary'])->json('id');
        DB::table('actor_invitations')->where('id', $one)->update(['id' => $one]); // keep
        $other = $this->invite($page, $page['user'], ['phone' => $this->phoneOf($second['user']), 'kind' => 'transfer_primary'])->json('id');

        $this->actingAsUser($first['user'])->postJson("/api/v1/invitations/$one/respond", ['accept' => true])->assertOk();
        $this->actingAsUser($second['user'])->postJson("/api/v1/invitations/$other/respond", ['accept' => true])->assertStatus(409)->assertJsonPath('code', 'invitation.inviter_not_primary');
        $this->assertSame(1, DB::table('actor_members')->where('actor_id', $page['actor'])->where('is_primary', true)->count());
    }

    public function test_personal_actors_cannot_start_a_transfer(): void
    {
        $me = $this->person();

        $this->actingAsUser($me['user'])->postJson("/api/v1/actors/{$me['actor']}/invitations", ['phone' => '0701234567', 'kind' => 'transfer_primary'])->assertForbidden();
    }

    // ---------------------------------------------------------------- roles and removal

    public function test_managers_change_roles_but_not_the_primary_owner(): void
    {
        $page = $this->business();
        $editor = $this->member($page['actor'], 'editor');
        $url = "/api/v1/actors/{$page['actor']}/members";

        $this->actingAsUser($page['user'])->patchJson("$url/$editor", ['role' => 'admin'])->assertNoContent();
        $this->assertDatabaseHas('actor_members', ['actor_id' => $page['actor'], 'user_id' => $editor, 'role' => 'admin']);
        $this->actingAsUser($page['user'])->patchJson("$url/{$page['user']}", ['role' => 'admin'])->assertUnprocessable()->assertJsonPath('code', 'member.primary_locked');
        $this->actingAsUser($this->member($page['actor'], 'editor'))->patchJson("$url/$editor", ['role' => 'viewer'])->assertForbidden();
        $this->actingAsUser($page['user'])->patchJson("$url/$editor", ['role' => 'king'])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.role_changed', 'actor_id' => $page['actor']]);
    }

    public function test_admins_cannot_touch_owners_or_make_owners(): void
    {
        $page = $this->business();
        $admin = $this->member($page['actor'], 'admin');
        $coOwner = $this->member($page['actor'], 'owner');
        $editor = $this->member($page['actor'], 'editor');
        $url = "/api/v1/actors/{$page['actor']}/members";

        $this->actingAsUser($admin)->patchJson("$url/$coOwner", ['role' => 'viewer'])->assertForbidden()->assertJsonPath('code', 'member.admin_cannot_manage_owners');
        $this->actingAsUser($admin)->patchJson("$url/$editor", ['role' => 'owner'])->assertForbidden();
        $this->actingAsUser($admin)->deleteJson("$url/$coOwner")->assertForbidden();
        $this->actingAsUser($admin)->patchJson("$url/$editor", ['role' => 'admin'])->assertNoContent();
        $this->actingAsUser($admin)->deleteJson("$url/$editor")->assertNoContent();
    }

    public function test_the_primary_owner_cannot_be_removed_and_a_co_owner_can_be(): void
    {
        $page = $this->business();
        $co = $this->member($page['actor'], 'owner');
        $url = "/api/v1/actors/{$page['actor']}/members";

        $this->actingAsUser($co)->deleteJson("$url/{$page['user']}")->assertUnprocessable()->assertJsonPath('code', 'member.primary_locked');
        $this->actingAsUser($page['user'])->deleteJson("$url/$co")->assertNoContent();
        $this->assertDatabaseMissing('actor_members', ['actor_id' => $page['actor'], 'user_id' => $co]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.removed', 'actor_id' => $page['actor']]);
    }

    public function test_any_member_can_leave_a_page(): void
    {
        $page = $this->business();
        $viewer = $this->member($page['actor'], 'viewer');

        $this->actingAsUser($viewer)->deleteJson("/api/v1/actors/{$page['actor']}/members/$viewer")->assertNoContent();
        $this->assertDatabaseMissing('actor_members', ['actor_id' => $page['actor'], 'user_id' => $viewer]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.left']);
    }

    public function test_a_viewer_cannot_remove_someone_else(): void
    {
        $page = $this->business();
        $viewer = $this->member($page['actor'], 'viewer');
        $editor = $this->member($page['actor'], 'editor');

        $this->actingAsUser($viewer)->deleteJson("/api/v1/actors/{$page['actor']}/members/$editor")->assertForbidden();
    }

    public function test_a_demoted_last_non_primary_owner_scenario_keeps_one_owner(): void
    {
        $page = $this->business();

        // the primary owner is the only owner and cannot be demoted by anybody
        $this->actingAsUser($page['user'])->patchJson("/api/v1/actors/{$page['actor']}/members/{$page['user']}", ['role' => 'admin'])->assertUnprocessable();
        $this->assertSame(1, DB::table('actor_members')->where(['actor_id' => $page['actor'], 'role' => 'owner'])->count());
    }

    public function test_removing_an_unknown_member_is_404(): void
    {
        $page = $this->business();

        $this->actingAsUser($page['user'])->deleteJson("/api/v1/actors/{$page['actor']}/members/".Str::uuid())->assertNotFound();
    }
}
