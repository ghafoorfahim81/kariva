<?php

namespace Tests\Feature\Api;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class VerificationApiTest extends ApiTestCase
{
    private array $owner;

    private array $page;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->owner = $this->person();
        $this->page = $this->business($this->owner);
    }

    private function as(array $who, ?string $actor = null): static
    {
        return $this->actingAsUser($who['user'], $actor ?? $who['actor']);
    }

    private function request(?string $actor = null)
    {
        return $this->as($this->owner, $actor)->postJson('/api/v1/verification');
    }

    private function upload(string $user, string $request, ?UploadedFile $file = null)
    {
        return $this->actingAsUser($user)->post('/api/v1/media', [
            'model_type' => 'verification_request', 'model_id' => $request, 'collection' => 'document',
            'file' => $file ?? UploadedFile::fake()->image('id.jpg', 800, 600)->size(300),
        ], ['Accept' => 'application/json']);
    }

    public function test_an_owner_opens_a_request_and_sees_its_status(): void
    {
        $this->as($this->owner)->getJson('/api/v1/verification')->assertOk()->assertJsonPath('verified', false)->assertJsonPath('request', null);

        $this->request()->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('kind', 'person')->assertJsonPath('documents', 0);
        $this->as($this->owner)->getJson('/api/v1/verification')->assertJsonPath('request.status', 'pending');
    }

    public function test_the_kind_follows_the_actor_type(): void
    {
        $this->request($this->page['actor'])->assertCreated()->assertJsonPath('kind', 'business');
    }

    public function test_one_pending_request_at_a_time(): void
    {
        $this->request()->assertCreated();

        $this->request()->assertStatus(409)->assertJsonPath('code', 'verification.already_pending');
    }

    public function test_only_owners_can_ask_and_it_needs_login(): void
    {
        $admin = $this->member($this->page['actor'], 'admin');
        $editor = $this->member($this->page['actor'], 'editor');

        $this->actingAsUser($admin, $this->page['actor'])->postJson('/api/v1/verification')->assertForbidden();
        $this->actingAsUser($editor, $this->page['actor'])->postJson('/api/v1/verification')->assertForbidden();
        $this->actingAsUser($this->person()['user'], $this->page['actor'])->postJson('/api/v1/verification')->assertForbidden();
        auth()->forgetGuards();
        $this->withoutHeader('X-Actor-Id')->postJson('/api/v1/verification')->assertUnauthorized();
    }

    public function test_an_already_verified_actor_cannot_ask_again(): void
    {
        DB::table('actors')->where('id', $this->owner['actor'])->update(['verified_at' => now()]);

        $this->request()->assertStatus(409)->assertJsonPath('code', 'verification.already_verified');
        $this->as($this->owner)->getJson('/api/v1/verification')->assertJsonPath('verified', true);
    }

    public function test_documents_go_to_the_private_disk_and_never_get_a_url(): void
    {
        $id = $this->request()->json('id');

        $res = $this->upload($this->owner['user'], $id)->assertCreated()->assertJsonPath('url', null);

        $row = DB::table('media')->where('id', $res->json('id'))->first();
        $this->assertSame('private', $row->visibility);
        $this->assertSame(config('kariva.media.private_disk'), $row->disk);
        Storage::disk('local')->assertExists($row->path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->as($this->owner)->getJson('/api/v1/verification')->assertJsonPath('request.documents', 1);
    }

    public function test_document_photos_are_images_with_a_limit_of_four(): void
    {
        $id = $this->request()->json('id');

        foreach (range(1, 4) as $i) {
            $this->upload($this->owner['user'], $id)->assertCreated();
        }
        $this->upload($this->owner['user'], $id)->assertUnprocessable()->assertJsonPath('code', 'media.limit_reached');
    }

    public function test_only_an_owner_of_that_actor_can_add_documents(): void
    {
        $id = $this->request()->json('id');
        $editor = $this->member($this->owner['actor'], 'editor');

        $this->upload($this->person()['user'], $id)->assertForbidden();
        $this->upload($editor, $id)->assertForbidden();
    }

    public function test_documents_cannot_be_added_after_the_decision(): void
    {
        $id = $this->request()->json('id');
        DB::table('verification_requests')->where('id', $id)->update(['status' => 'rejected', 'reviewed_by' => $this->person()['user'], 'reviewed_at' => now()]);

        $this->upload($this->owner['user'], $id)->assertStatus(409)->assertJsonPath('code', 'verification.not_pending');
    }

    public function test_the_owner_can_remove_a_document_while_pending(): void
    {
        $id = $this->request()->json('id');
        $media = $this->upload($this->owner['user'], $id)->json('id');

        $this->actingAsUser($this->person()['user'])->deleteJson("/api/v1/media/$media")->assertForbidden();
        $this->actingAsUser($this->owner['user'])->deleteJson("/api/v1/media/$media")->assertNoContent();
        $this->assertSame(0, DB::table('media')->count());
    }

    public function test_the_database_has_no_place_for_a_document_number(): void
    {
        $columns = DB::table('information_schema.columns')->where('table_name', 'verification_requests')->pluck('column_name')->implode(' ');

        $this->assertStringNotContainsString('number', $columns);
        $this->assertStringNotContainsString('tazkira', $columns);
    }
}
