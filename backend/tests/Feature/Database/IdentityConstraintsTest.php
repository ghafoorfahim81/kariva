<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;

class IdentityConstraintsTest extends DatabaseTestCase
{
    // ---------------------------------------------------------------- users

    public function test_phone_must_be_an_afghan_e164_number(): void
    {
        foreach (['0791234567', '+93791234567 ', '+447911123456', '+93691234567', '+9379123456', '+93791234567890', 'abc'] as $bad) {
            $this->assertRejected(fn () => $this->insert('users', ['phone_e164' => $bad]), 'users_phone_format');
        }

        $this->insert('users', ['phone_e164' => '+93791234567']);
        $this->assertDatabaseHas('users', ['phone_e164' => '+93791234567']);
    }

    public function test_phone_is_unique(): void
    {
        $this->insert('users', ['phone_e164' => '+93791234567']);

        $this->assertRejected(fn () => $this->insert('users', ['phone_e164' => '+93791234567']), 'users_phone_e164_unique');
    }

    public function test_email_is_optional_but_unique_ignoring_case(): void
    {
        $this->insert('users', ['phone_e164' => $this->phone()]);
        $this->insert('users', ['phone_e164' => $this->phone()]);
        $this->insert('users', ['phone_e164' => $this->phone(), 'email' => 'Ali@Example.com']);

        $this->assertRejected(fn () => $this->insert('users', ['phone_e164' => $this->phone(), 'email' => 'ali@example.com']), 'users_email_unique');
        $this->assertRejected(fn () => $this->insert('users', ['phone_e164' => $this->phone(), 'email' => 'not-an-email']), 'users_email_format');
    }

    public function test_status_and_locale_are_restricted(): void
    {
        $this->assertRejected(fn () => $this->insert('users', ['phone_e164' => $this->phone(), 'status' => 'banned']), 'users_status_valid');
        $this->assertRejected(fn () => $this->insert('users', ['phone_e164' => $this->phone(), 'locale' => 'de']), 'users_locale_valid');
    }

    public function test_deleted_users_can_be_anonymized_but_active_ones_cannot_get_a_bad_phone(): void
    {
        $user = $this->person()['user'];

        $this->assertRejected(fn () => DB::table('users')->where('id', $user)->update(['phone_e164' => 'deleted:x']), 'users_phone_format');

        DB::table('users')->where('id', $user)->update(['phone_e164' => 'deleted:'.$user, 'deleted_at' => now()]);
        $this->assertDatabaseHas('users', ['id' => $user, 'phone_e164' => 'deleted:'.$user]);
    }

    public function test_every_user_must_have_a_personal_actor_by_commit_time(): void
    {
        $this->assertRejected(function () {
            $this->insert('users', ['phone_e164' => $this->phone()]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }, 'has no personal actor');
    }

    public function test_user_and_actor_can_be_created_in_one_transaction(): void
    {
        DB::transaction(function () {
            $this->person();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });

        $this->assertSame(1, DB::table('actors')->count());
    }

    // ---------------------------------------------------------------- otp

    public function test_otp_phone_attempts_purpose_and_expiry_are_checked(): void
    {
        $base = ['phone_e164' => '+93791234567', 'code_hash' => 'x', 'expires_at' => now()->addMinutes(5)];
        $this->insert('otp_codes', $base);

        $this->assertRejected(fn () => $this->insert('otp_codes', ['attempts' => 6] + $base), 'otp_attempts_max');
        $this->assertRejected(fn () => $this->insert('otp_codes', ['attempts' => -1] + $base), 'otp_attempts_max');
        $this->assertRejected(fn () => $this->insert('otp_codes', ['purpose' => 'signup'] + $base), 'otp_purpose_valid');
        $this->assertRejected(fn () => $this->insert('otp_codes', ['phone_e164' => '0791234567'] + $base), 'otp_phone_format');
        $this->assertRejected(fn () => $this->insert('otp_codes', ['expires_at' => now()->subMinute()] + $base), 'otp_expiry_after_creation');
    }

    // ---------------------------------------------------------------- actors

    public function test_actor_type_must_match_personal_user(): void
    {
        $user = $this->insert('users', ['phone_e164' => $this->phone()]);

        $this->assertRejected(fn () => $this->insert('actors', ['type' => 'person', 'display_name' => 'x']), 'actors_type_matches_owner');
        $this->assertRejected(fn () => $this->insert('actors', ['type' => 'business', 'personal_user_id' => $user, 'display_name' => 'x']), 'actors_type_matches_owner');
        $this->assertRejected(fn () => $this->insert('actors', ['type' => 'company', 'display_name' => 'x']), 'actors_type_valid');
    }

    public function test_a_user_has_only_one_personal_actor(): void
    {
        $person = $this->person();

        $this->assertRejected(fn () => $this->insert('actors', ['type' => 'person', 'personal_user_id' => $person['user'], 'display_name' => 'again']), 'actors_personal_user_unique');
    }

    public function test_actor_contact_numbers_and_status_are_validated(): void
    {
        $this->assertRejected(fn () => $this->business(null, ['contact_phone_e164' => '0791234567']), 'actors_contact_phone_format');
        $this->assertRejected(fn () => $this->business(null, ['whatsapp_e164' => '+1555']), 'actors_whatsapp_format');
        $this->assertRejected(fn () => $this->business(null, ['status' => 'banned']), 'actors_status_valid');
        $this->assertRejected(fn () => $this->business(null, ['display_name' => '  ']), 'actors_name_not_blank');
    }

    public function test_actor_type_and_personal_user_never_change(): void
    {
        $person = $this->person();
        $other = $this->person();

        $this->assertRejected(fn () => DB::table('actors')->where('id', $person['actor'])->update(['personal_user_id' => $other['user']]), 'immutable');
    }

    // ---------------------------------------------------------------- members

    public function test_member_roles_are_restricted(): void
    {
        $page = $this->business();

        $this->assertRejected(fn () => $this->member($page['actor'], 'boss'), 'actor_members_role_valid');
    }

    public function test_a_page_has_one_primary_owner_but_can_have_several_owners(): void
    {
        $page = $this->business();
        $this->member($page['actor'], 'owner');
        $this->member($page['actor'], 'owner');

        $second = $this->person();
        $this->assertRejected(
            fn () => DB::table('actor_members')->insert(['actor_id' => $page['actor'], 'user_id' => $second['user'], 'role' => 'owner', 'is_primary' => true]),
            'one_primary_owner'
        );
    }

    public function test_only_an_owner_can_be_primary(): void
    {
        $page = $this->business();
        $user = $this->person();

        $this->assertRejected(
            fn () => DB::table('actor_members')->insert(['actor_id' => $page['actor'], 'user_id' => $user['user'], 'role' => 'admin', 'is_primary' => true]),
            'primary_must_be_owner'
        );
    }

    public function test_the_last_owner_cannot_be_removed_or_demoted(): void
    {
        $person = $this->person();
        $page = $this->business();
        $co = $this->member($page['actor'], 'owner');

        $this->assertRejected(fn () => DB::table('actor_members')->where(['actor_id' => $person['actor'], 'user_id' => $person['user']])->update(['role' => 'admin', 'is_primary' => false]), 'last owner');

        // A co-owner is not the last owner and can leave.
        DB::table('actor_members')->where(['actor_id' => $page['actor'], 'user_id' => $co])->delete();
        $this->assertDatabaseMissing('actor_members', ['actor_id' => $page['actor'], 'user_id' => $co]);
    }

    public function test_the_primary_owner_cannot_be_removed_while_the_page_exists(): void
    {
        $page = $this->business();
        $this->member($page['actor'], 'owner');

        $this->assertRejected(fn () => DB::table('actor_members')->where(['actor_id' => $page['actor'], 'user_id' => $page['user']])->delete(), 'primary owner');
    }

    public function test_primary_ownership_moves_to_another_owner_in_two_steps(): void
    {
        $page = $this->business();
        $newOwner = $this->member($page['actor'], 'owner');

        DB::transaction(function () use ($page, $newOwner) {
            DB::table('actor_members')->where(['actor_id' => $page['actor'], 'user_id' => $page['user']])->update(['is_primary' => false]);
            DB::table('actor_members')->where(['actor_id' => $page['actor'], 'user_id' => $newOwner])->update(['is_primary' => true]);
        });

        $this->assertDatabaseHas('actor_members', ['actor_id' => $page['actor'], 'user_id' => $newOwner, 'is_primary' => true]);
    }

    // ---------------------------------------------------------------- invitations

    private function invitation(array $page, array $overrides = []): array
    {
        return $overrides + [
            'actor_id' => $page['actor'], 'kind' => 'member', 'role' => 'editor', 'invited_phone_e164' => '+93791234567',
            'invited_by_user_id' => $page['user'], 'expires_at' => now()->addDays(7),
        ];
    }

    public function test_only_one_pending_invitation_per_actor_phone_and_kind(): void
    {
        $page = $this->business();
        $this->insert('actor_invitations', $this->invitation($page));

        $this->assertRejected(fn () => $this->insert('actor_invitations', $this->invitation($page)), 'one_pending_invitation');

        // A different kind or a finished invitation does not conflict.
        $this->insert('actor_invitations', $this->invitation($page, ['kind' => 'transfer_primary', 'role' => 'owner']));
        DB::table('actor_invitations')->where('actor_id', $page['actor'])->where('kind', 'member')->update(['status' => 'declined', 'responded_at' => now()]);
        $this->insert('actor_invitations', $this->invitation($page));
    }

    public function test_invitation_fields_are_validated(): void
    {
        $page = $this->business();

        $this->assertRejected(fn () => $this->insert('actor_invitations', $this->invitation($page, ['invited_phone_e164' => '0791234567'])), 'actor_invitations_phone_format');
        $this->assertRejected(fn () => $this->insert('actor_invitations', $this->invitation($page, ['kind' => 'other'])), 'actor_invitations_kind_valid');
        $this->assertRejected(fn () => $this->insert('actor_invitations', $this->invitation($page, ['status' => 'maybe'])), 'actor_invitations_status_valid');
        $this->assertRejected(fn () => $this->insert('actor_invitations', $this->invitation($page, ['kind' => 'transfer_primary', 'role' => 'admin'])), 'actor_invitations_transfer_is_owner');
        $this->assertRejected(fn () => $this->insert('actor_invitations', $this->invitation($page, ['status' => 'accepted'])), 'actor_invitations_responded_pair');
    }

    public function test_only_the_primary_owner_can_start_a_primary_transfer(): void
    {
        $page = $this->business();
        $coOwner = $this->member($page['actor'], 'owner');

        $this->assertRejected(
            fn () => $this->insert('actor_invitations', $this->invitation($page, ['kind' => 'transfer_primary', 'role' => 'owner', 'invited_by_user_id' => $coOwner])),
            'only the primary owner'
        );
        $this->insert('actor_invitations', $this->invitation($page, ['kind' => 'transfer_primary', 'role' => 'owner']));
    }

    public function test_admins_can_invite_members_but_not_owners_and_editors_cannot_invite(): void
    {
        $page = $this->business();
        $admin = $this->member($page['actor'], 'admin');
        $editor = $this->member($page['actor'], 'editor');

        $this->insert('actor_invitations', $this->invitation($page, ['invited_by_user_id' => $admin]));
        $this->assertRejected(fn () => $this->insert('actor_invitations', $this->invitation($page, ['invited_by_user_id' => $admin, 'role' => 'owner', 'invited_phone_e164' => '+93791234568'])), 'admins cannot invite owners');
        $this->assertRejected(fn () => $this->insert('actor_invitations', $this->invitation($page, ['invited_by_user_id' => $editor, 'invited_phone_e164' => '+93791234569'])), 'only owners and admins');
        $this->insert('actor_invitations', $this->invitation($page, ['role' => 'owner', 'invited_phone_e164' => '+93791234570']));
    }

    // ---------------------------------------------------------------- deletion

    public function test_any_owner_requests_deletion_and_only_the_primary_owner_confirms(): void
    {
        $page = $this->business();
        $coOwner = $this->member($page['actor'], 'owner');
        $admin = $this->member($page['actor'], 'admin');
        $request = ['deletion_requested_by' => $coOwner, 'deletion_requested_at' => now()];

        $this->assertRejected(fn () => DB::table('actors')->where('id', $page['actor'])->update(['status' => 'deleted']), 'actors_business_delete_needs_request');
        $this->assertRejected(fn () => DB::table('actors')->where('id', $page['actor'])->update(['deletion_requested_by' => $admin, 'deletion_requested_at' => now()]), 'only an owner');

        DB::table('actors')->where('id', $page['actor'])->update($request);
        $this->assertRejected(fn () => DB::table('actors')->where('id', $page['actor'])->update(['deletion_confirmed_by' => $coOwner]), 'only the primary owner');
        $this->assertRejected(fn () => DB::table('actors')->where('id', $page['actor'])->update(['status' => 'deleted']), 'actors_business_delete_needs_request');

        DB::table('actors')->where('id', $page['actor'])->update(['deletion_confirmed_by' => $page['user'], 'status' => 'deleted', 'deleted_at' => now()]);
        $this->assertDatabaseHas('actors', ['id' => $page['actor'], 'status' => 'deleted']);
    }

    public function test_deletion_request_columns_travel_together(): void
    {
        $page = $this->business();

        $this->assertRejected(fn () => DB::table('actors')->where('id', $page['actor'])->update(['deletion_requested_by' => $page['user']]), 'actors_deletion_request_pair');
    }

    // ---------------------------------------------------------------- verification

    public function test_verification_kind_must_match_the_actor_type(): void
    {
        $person = $this->person();
        $page = $this->business();

        $this->insert('verification_requests', ['actor_id' => $person['actor'], 'kind' => 'person', 'requested_by' => $person['user']]);
        $this->insert('verification_requests', ['actor_id' => $page['actor'], 'kind' => 'business', 'requested_by' => $page['user']]);
        $this->assertRejected(fn () => $this->insert('verification_requests', ['actor_id' => $page['actor'], 'kind' => 'person', 'requested_by' => $page['user']]), 'verification_actor_type_fk');
    }

    public function test_one_pending_verification_per_actor_and_kind(): void
    {
        $person = $this->person();
        $row = ['actor_id' => $person['actor'], 'kind' => 'person', 'requested_by' => $person['user']];
        $this->insert('verification_requests', $row);

        $this->assertRejected(fn () => $this->insert('verification_requests', $row), 'one_pending_verification');
    }

    public function test_a_decided_verification_records_who_and_when(): void
    {
        $person = $this->person();
        $admin = $this->person();
        $row = ['actor_id' => $person['actor'], 'kind' => 'person', 'requested_by' => $person['user']];

        $this->assertRejected(fn () => $this->insert('verification_requests', $row + ['status' => 'approved']), 'verification_reviewed_when_decided');
        $this->insert('verification_requests', $row + ['status' => 'approved', 'reviewed_by' => $admin['user'], 'reviewed_at' => now()]);
        $this->insert('verification_requests', $row); // a new pending one is allowed after the old one was decided
    }

    public function test_verification_has_no_column_for_the_document_number(): void
    {
        $columns = DB::table('information_schema.columns')->where('table_name', 'verification_requests')->pluck('column_name')->all();

        foreach ($columns as $column) {
            $this->assertStringNotContainsString('number', $column);
            $this->assertStringNotContainsString('tazkira', $column);
        }
    }

    // ---------------------------------------------------------------- audit

    public function test_audit_logs_are_append_only(): void
    {
        $id = $this->insert('audit_logs', ['action' => 'actor.transfer_primary']);

        $this->assertRejected(fn () => DB::table('audit_logs')->where('id', $id)->update(['action' => 'other']), 'append-only');
        $this->assertRejected(fn () => DB::table('audit_logs')->where('id', $id)->delete(), 'append-only');
        $this->assertRejected(fn () => $this->insert('audit_logs', ['action' => ' ']), 'audit_action_not_blank');
    }
}
