<?php

namespace Tests\Feature\Database;

use App\Jobs\PurgeMedia;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaConstraintsTest extends DatabaseTestCase
{
    private function media(string $type, string $modelId, string $collection, array $o = []): string
    {
        $public = in_array($type, ['actor', 'service', 'portfolio_item'], true);

        return $this->insert('media', $o + [
            'model_type' => $type, 'model_id' => $modelId, 'collection' => $collection,
            'visibility' => $public ? 'public' : 'private', 'disk' => 'local', 'path' => 'f/'.$this->seq++.'.jpg',
            'mime' => 'image/jpeg', 'size_bytes' => 1000,
        ]);
    }

    public function test_each_model_type_only_accepts_its_own_collections(): void
    {
        $id = $this->person()['actor'];

        $this->media('actor', $id, 'avatar');
        $this->media('service', $id, 'gallery');
        $this->media('portfolio_item', $id, 'images');
        $this->media('order', $id, 'attachments');
        $this->media('order', $id, 'voice');
        $this->media('message', $id, 'attachment');
        $this->media('message', $id, 'voice');
        $this->media('verification_request', $id, 'document');

        $this->assertRejected(fn () => $this->media('actor', $id, 'gallery', ['visibility' => 'public']), 'media_collection_valid');
        $this->assertRejected(fn () => $this->media('service', $id, 'avatar'), 'media_collection_valid');
        $this->assertRejected(fn () => $this->media('message', $id, 'attachments'), 'media_collection_valid');
        $this->assertRejected(fn () => $this->media('review', $id, 'images'), 'media');
    }

    public function test_visibility_follows_the_collection_not_the_user(): void
    {
        $id = $this->person()['actor'];

        $this->assertRejected(fn () => $this->media('actor', $id, 'avatar', ['visibility' => 'private']), 'media_collection_valid');
        $this->assertRejected(fn () => $this->media('verification_request', $id, 'document', ['visibility' => 'public']), 'media_collection_valid');
        $this->assertRejected(fn () => $this->media('order', $id, 'attachments', ['visibility' => 'public']), 'media_collection_valid');
        $this->assertRejected(fn () => $this->media('actor', $id, 'cover', ['visibility' => 'secret']), 'media_collection_valid');
    }

    public function test_an_actor_has_one_avatar_and_one_cover(): void
    {
        $id = $this->person()['actor'];
        $this->media('actor', $id, 'avatar');
        $this->media('actor', $id, 'cover');

        $this->assertRejected(fn () => $this->media('actor', $id, 'avatar'), 'one_single_media');
        $this->assertRejected(fn () => $this->media('actor', $id, 'cover'), 'one_single_media');
        $this->media('actor', $this->person()['actor'], 'avatar'); // another actor is fine
    }

    public function test_galleries_and_attachments_can_hold_many_files(): void
    {
        $id = $this->business()['actor'];

        foreach (range(1, 3) as $i) {
            $this->media('service', $id, 'gallery', ['sort_order' => $i]);
            $this->media('order', $id, 'attachments');
        }

        $this->assertSame(3, DB::table('media')->where('collection', 'gallery')->count());
    }

    public function test_scan_status_size_and_default_are_checked(): void
    {
        $id = $this->person()['actor'];
        $media = $this->media('actor', $id, 'avatar');

        $this->assertSame('pending', DB::table('media')->where('id', $media)->value('scan_status'));
        $this->assertRejected(fn () => $this->media('actor', $id, 'cover', ['scan_status' => 'unknown']), 'media_scan_status_valid');
        $this->assertRejected(fn () => $this->media('actor', $id, 'cover', ['size_bytes' => 0]), 'media_size_positive');
    }

    public function test_morph_map_uses_short_names_not_class_names(): void
    {
        $map = Relation::morphMap();

        foreach (['actor', 'service', 'portfolio_item', 'order', 'message', 'verification_request', 'review', 'need'] as $name) {
            $this->assertArrayHasKey($name, $map);
            $this->assertStringStartsWith('App\\Models\\', $map[$name]);
        }
    }

    public function test_every_media_model_type_resolves_to_a_table(): void
    {
        foreach (['actor', 'service', 'portfolio_item', 'order', 'message', 'verification_request'] as $type) {
            $table = (new (Relation::getMorphedModel($type)))->getTable();
            $this->assertTrue(Schema::hasTable($table), "$type -> $table");
        }
    }

    // ---------------------------------------------------------------- nightly purge job

    public function test_the_nightly_job_removes_media_whose_parent_is_gone(): void
    {
        Storage::fake('local');
        $actor = $this->business()['actor'];
        $service = $this->service($actor);
        $gone = (string) Str::uuid();

        $kept = $this->media('service', $service, 'gallery', ['path' => 'kept.jpg']);
        $orphan = $this->media('service', $gone, 'gallery', ['path' => 'orphan.jpg']);
        $orphanMessage = $this->media('message', $gone, 'voice', ['path' => 'voice.m4a']);
        Storage::disk('local')->put('kept.jpg', 'x');
        Storage::disk('local')->put('orphan.jpg', 'x');
        Storage::disk('local')->put('voice.m4a', 'x');

        (new PurgeMedia)->handle();

        $this->assertDatabaseHas('media', ['id' => $kept]);
        $this->assertDatabaseMissing('media', ['id' => $orphan]);
        $this->assertDatabaseMissing('media', ['id' => $orphanMessage]);
        Storage::disk('local')->assertExists('kept.jpg');
        Storage::disk('local')->assertMissing('orphan.jpg');
        Storage::disk('local')->assertMissing('voice.m4a');
    }

    public function test_the_nightly_job_removes_media_past_its_purge_date(): void
    {
        Storage::fake('local');
        $person = $this->person();
        $request = $this->insert('verification_requests', ['actor_id' => $person['actor'], 'kind' => 'person', 'requested_by' => $person['user']]);

        $due = $this->media('verification_request', $request, 'document', ['path' => 'due.jpg', 'purge_at' => now()->subDay()]);
        $later = $this->media('verification_request', $request, 'document', ['path' => 'later.jpg', 'purge_at' => now()->addDay()]);
        $none = $this->media('verification_request', $request, 'document', ['path' => 'none.jpg']);
        Storage::disk('local')->put('due.jpg', 'x');

        (new PurgeMedia)->handle();

        $this->assertDatabaseMissing('media', ['id' => $due]);
        $this->assertDatabaseHas('media', ['id' => $later]);
        $this->assertDatabaseHas('media', ['id' => $none]);
        Storage::disk('local')->assertMissing('due.jpg');
    }
}
