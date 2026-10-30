<?php

namespace Tests\Feature\Api;

use App\Contracts\MalwareScanner;
use App\Jobs\ScanMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function upload(string $user, string $type, string $id, string $collection, ?UploadedFile $file = null)
    {
        return $this->actingAsUser($user)->post('/api/v1/media', [
            'model_type' => $type, 'model_id' => $id, 'collection' => $collection,
            'file' => $file ?? UploadedFile::fake()->image('photo.jpg', 600, 400)->size(200),
        ], ['Accept' => 'application/json']);
    }

    public function test_an_avatar_is_stored_scanned_and_shown_on_the_profile(): void
    {
        $me = $this->person();

        $response = $this->upload($me['user'], 'actor', $me['actor'], 'avatar')->assertCreated()->assertJsonPath('scan_status', 'clean');

        $row = DB::table('media')->where('id', $response->json('id'))->first();
        $this->assertSame('public', $row->visibility);
        $this->assertSame('public', $row->disk);
        $this->assertStringStartsWith("actor/{$me['actor']}/", $row->path);
        Storage::disk('public')->assertExists($row->path);
        $this->assertSame($me['user'], $row->created_by);
        $this->assertNotNull($this->getJson("/api/v1/actors/{$me['actor']}")->json('avatar_url'));
    }

    public function test_the_stored_name_is_random_and_has_the_extension_of_the_real_type(): void
    {
        $me = $this->person();
        $file = UploadedFile::fake()->image('../../evil.php.png', 100, 100);

        $this->upload($me['user'], 'actor', $me['actor'], 'avatar', $file)->assertCreated();

        $path = DB::table('media')->value('path');
        $this->assertStringNotContainsString('evil', $path);
        $this->assertStringEndsWith('.png', $path);
    }

    public function test_a_new_avatar_replaces_the_old_one_and_its_file(): void
    {
        $me = $this->person();
        $this->upload($me['user'], 'actor', $me['actor'], 'avatar')->assertCreated();
        $old = DB::table('media')->value('path');

        $this->upload($me['user'], 'actor', $me['actor'], 'avatar')->assertCreated();

        $this->assertSame(1, DB::table('media')->count());
        Storage::disk('public')->assertMissing($old);
    }

    public function test_avatar_and_cover_live_side_by_side(): void
    {
        $me = $this->person();
        $this->upload($me['user'], 'actor', $me['actor'], 'avatar')->assertCreated();
        $this->upload($me['user'], 'actor', $me['actor'], 'cover')->assertCreated();

        $profile = $this->getJson("/api/v1/actors/{$me['actor']}");
        $this->assertNotNull($profile->json('avatar_url'));
        $this->assertNotNull($profile->json('cover_url'));
    }

    /** A real file on disk (not Laravel's fake, which trusts the name), so the type is sniffed from its content. */
    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'kv');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);   // the client claims "image/jpeg"
    }

    public function test_only_images_are_accepted_judged_by_content_not_name(): void
    {
        $me = $this->person();
        $url = fn (UploadedFile $f) => $this->upload($me['user'], 'actor', $me['actor'], 'avatar', $f);

        $url($this->realFile('photo.jpg', '<?php system($_GET["c"]); ?>'))->assertUnprocessable()->assertJsonPath('code', 'media.invalid_type');
        $url($this->realFile('photo.png', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF"))->assertUnprocessable()->assertJsonPath('code', 'media.invalid_type');
        $url($this->realFile('photo.png', 'GIF89a'.str_repeat("\0", 60)))->assertUnprocessable()->assertJsonPath('code', 'media.invalid_type');
        $this->assertSame(0, DB::table('media')->count());

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $url($this->realFile('whatever.txt', $png))->assertCreated();
        $this->assertSame('image/png', DB::table('media')->value('mime'));
    }

    public function test_size_limits_follow_the_collection(): void
    {
        $me = $this->person();
        $big = UploadedFile::fake()->image('big.jpg', 3000, 3000)->size(4000);

        $this->upload($me['user'], 'actor', $me['actor'], 'avatar', $big)->assertUnprocessable()->assertJsonPath('code', 'media.too_large')->assertJsonPath('max_kb', 3072);
        $this->upload($me['user'], 'actor', $me['actor'], 'cover', UploadedFile::fake()->image('c.jpg')->size(4000))->assertCreated();
    }

    public function test_only_people_who_may_edit_the_page_can_upload(): void
    {
        $page = $this->business();
        $viewer = $this->member($page['actor'], 'viewer');
        $editor = $this->member($page['actor'], 'editor');

        $this->upload($viewer, 'actor', $page['actor'], 'avatar')->assertForbidden();
        $this->upload($this->person()['user'], 'actor', $page['actor'], 'avatar')->assertForbidden();
        $this->upload($editor, 'actor', $page['actor'], 'avatar')->assertCreated();
        $this->assertSame(1, DB::table('media')->count());
    }

    public function test_uploading_needs_login_and_a_known_target(): void
    {
        $this->postJson('/api/v1/media', [])->assertUnauthorized();

        $me = $this->person();
        $this->upload($me['user'], 'actor', (string) Str::uuid(), 'avatar')->assertNotFound();
        $this->upload($me['user'], 'actor', $me['actor'], 'banner')->assertUnprocessable()->assertJsonPath('code', 'media.collection_unsupported');
        $this->upload($me['user'], 'order', $me['actor'], 'attachments')->assertUnprocessable();
        $this->upload($me['user'], 'verification_request', $me['actor'], 'document')->assertUnprocessable();
    }

    public function test_a_service_gallery_holds_eight_photos(): void
    {
        $page = $this->business();
        $service = $this->service($page['actor']);

        foreach (range(1, 8) as $i) {
            $this->upload($page['user'], 'service', $service, 'gallery')->assertCreated();
        }
        $this->upload($page['user'], 'service', $service, 'gallery')->assertUnprocessable()->assertJsonPath('code', 'media.limit_reached');

        $this->actingAsUser($page['user'])->getJson("/api/v1/services/$service")->assertJsonCount(8, 'images');
    }

    public function test_a_file_is_hidden_until_the_scan_marks_it_clean(): void
    {
        Queue::fake();
        $me = $this->person();

        $response = $this->upload($me['user'], 'actor', $me['actor'], 'avatar')->assertCreated()->assertJsonPath('scan_status', 'pending')->assertJsonPath('url', null);

        Queue::assertPushed(ScanMedia::class);
        $this->assertNull($this->getJson("/api/v1/actors/{$me['actor']}")->json('avatar_url'));

        (new ScanMedia($response->json('id')))->handle(app(MalwareScanner::class));
        $this->assertNotNull($this->getJson("/api/v1/actors/{$me['actor']}")->json('avatar_url'));
    }

    public function test_an_infected_file_is_deleted_and_never_shown(): void
    {
        $this->app->instance(MalwareScanner::class, new class implements MalwareScanner
        {
            public function isClean(string $absolutePath): bool
            {
                return false;
            }
        });
        $me = $this->person();

        $this->upload($me['user'], 'actor', $me['actor'], 'avatar')->assertUnprocessable()->assertJsonPath('code', 'media.infected');

        $this->assertSame(0, DB::table('media')->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertNull($this->getJson("/api/v1/actors/{$me['actor']}")->json('avatar_url'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'media.infected_removed']);
    }

    public function test_a_scan_that_cannot_run_leaves_the_file_pending_for_a_retry(): void
    {
        $this->app->instance(MalwareScanner::class, new class implements MalwareScanner
        {
            public function isClean(string $absolutePath): bool
            {
                throw new \RuntimeException('clamd is down');
            }
        });
        Queue::fake();
        $me = $this->person();
        $id = $this->upload($me['user'], 'actor', $me['actor'], 'avatar')->json('id');

        $this->expectException(\RuntimeException::class);
        (new ScanMedia($id))->handle(app(MalwareScanner::class));
    }

    public function test_the_owner_can_delete_a_photo_and_others_cannot(): void
    {
        $page = $this->business();
        $service = $this->service($page['actor']);
        $id = $this->upload($page['user'], 'service', $service, 'gallery')->json('id');
        $path = DB::table('media')->where('id', $id)->value('path');

        $this->actingAsUser($this->person()['user'])->deleteJson("/api/v1/media/$id")->assertForbidden();
        $this->actingAsUser($this->member($page['actor'], 'viewer'))->deleteJson("/api/v1/media/$id")->assertForbidden();
        $this->actingAsUser($page['user'])->deleteJson("/api/v1/media/$id")->assertNoContent();

        $this->assertDatabaseMissing('media', ['id' => $id]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_the_avatar_or_cover_can_be_removed_by_slot(): void
    {
        $page = $this->business();
        $this->upload($page['user'], 'actor', $page['actor'], 'avatar')->assertCreated();
        $this->upload($page['user'], 'actor', $page['actor'], 'cover')->assertCreated();
        $avatarPath = DB::table('media')->where('collection', 'avatar')->value('path');

        $this->actingAsUser($this->member($page['actor'], 'viewer'))->deleteJson("/api/v1/actors/{$page['actor']}/photos/avatar")->assertForbidden();
        $this->actingAsUser($this->person()['user'])->deleteJson("/api/v1/actors/{$page['actor']}/photos/avatar")->assertForbidden();
        $this->actingAsUser($page['user'])->deleteJson("/api/v1/actors/{$page['actor']}/photos/gallery")->assertNotFound();
        $this->actingAsUser($page['user'])->deleteJson("/api/v1/actors/{$page['actor']}/photos/avatar")->assertNoContent();

        Storage::disk('public')->assertMissing($avatarPath);
        $this->assertSame(['cover'], DB::table('media')->pluck('collection')->all());
        $this->actingAsUser($page['user'])->deleteJson("/api/v1/actors/{$page['actor']}/photos/avatar")->assertNoContent();   // already gone: still fine
    }

    public function test_search_cards_carry_the_avatar(): void
    {
        $page = $this->business(null, ['category_id' => $this->category()]);
        $this->upload($page['user'], 'actor', $page['actor'], 'avatar')->assertCreated();

        $item = collect($this->getJson('/api/v1/search/actors')->json('data'))->firstWhere('id', $page['actor']);
        $this->assertNotNull($item['avatar_url']);
    }

    // ---------------------------------------------------------------- portfolio

    public function test_work_samples_with_photos(): void
    {
        $page = $this->business();
        $service = $this->service($page['actor']);

        $item = $this->actingAsUser($page['user'])->postJson("/api/v1/actors/{$page['actor']}/portfolio", ['title' => 'نصب برق خانه', 'description' => 'کار ۲ روزه', 'service_id' => $service])->assertCreated()->json('id');
        $this->upload($page['user'], 'portfolio_item', $item, 'images')->assertCreated();
        $this->upload($page['user'], 'portfolio_item', $item, 'images')->assertCreated();

        $list = $this->actingAsUser($this->person()['user'])->getJson("/api/v1/actors/{$page['actor']}/portfolio")->assertOk();
        $list->assertJsonCount(1, 'data')->assertJsonCount(2, 'data.0.images')->assertJsonPath('data.0.title', 'نصب برق خانه');
    }

    public function test_a_work_sample_can_only_point_at_a_service_of_the_same_page(): void
    {
        $page = $this->business();
        $foreign = $this->service($this->business()['actor']);

        $this->actingAsUser($page['user'])->postJson("/api/v1/actors/{$page['actor']}/portfolio", ['title' => 'نمونه', 'service_id' => $foreign])->assertUnprocessable()->assertJsonValidationErrors('service_id');
    }

    public function test_portfolio_permissions_and_deletion_remove_the_photos(): void
    {
        $page = $this->business();
        $item = $this->actingAsUser($page['user'])->postJson("/api/v1/actors/{$page['actor']}/portfolio", ['title' => 'نمونه'])->json('id');
        $this->upload($page['user'], 'portfolio_item', $item, 'images')->assertCreated();
        $path = DB::table('media')->value('path');

        $this->actingAsUser($this->member($page['actor'], 'viewer'))->postJson("/api/v1/actors/{$page['actor']}/portfolio", ['title' => 'نمونه دو'])->assertForbidden();
        $this->actingAsUser($this->person()['user'])->patchJson("/api/v1/portfolio/$item", ['title' => 'هک'])->assertForbidden();
        $this->actingAsUser($page['user'])->patchJson("/api/v1/portfolio/$item", ['title' => 'عنوان تازه'])->assertOk()->assertJsonPath('title', 'عنوان تازه');
        $this->actingAsUser($page['user'])->deleteJson("/api/v1/portfolio/$item")->assertNoContent();

        $this->assertSame(0, DB::table('media')->count());
        Storage::disk('public')->assertMissing($path);
    }
}
