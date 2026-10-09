<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Release;
use App\Models\RepositoryIntegration;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RepositoryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_github_repository_urls_are_accepted(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $product = Product::create(['name' => 'Office', 'slug' => 'office', 'status' => 'active']);

        $this->actingAs($admin)->post(route('repositories.store'), [
            'product_id' => $product->id,
            'repository_url' => 'https://127.0.0.1/private/repository',
            'branch' => 'main',
            'release_channel' => 'stable',
        ])->assertSessionHasErrors('repository_url');

        $this->assertDatabaseCount('repository_integrations', 0);
    }

    public function test_repository_tokens_are_encrypted_at_rest(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'active' => true]);
        $product = Product::create(['name' => 'Office', 'slug' => 'office', 'status' => 'active']);
        $token = 'github_pat_test-secret-value';

        $this->actingAs($admin)->post(route('repositories.store'), [
            'product_id' => $product->id,
            'repository_url' => 'https://github.com/arashshokri/office',
            'branch' => 'main',
            'release_channel' => 'stable',
            'access_token' => $token,
            'auto_publish' => '1',
        ])->assertRedirect();

        $integration = RepositoryIntegration::firstOrFail();
        $stored = DB::table('repository_integrations')->where('id', $integration->id)->value('encrypted_access_token');
        $this->assertNotSame($token, $stored);
        $this->assertStringNotContainsString($token, $stored);
        $this->assertSame($token, $integration->encrypted_access_token);
        $this->assertTrue($integration->auto_publish);
    }

    private function runtimeZip(string $version = '3.8.19', string $variant = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'repo-runtime-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $manifest = ['format' => 'office-runtime-v1', 'product' => 'office', 'version' => $version,
            'source_protection' => 'ioncube', 'architecture' => 'amd64', 'images' => []];
        foreach (['app', 'db', 'redis', 'rdp-web', 'rdp-core'] as $role) {
            $body = 'fixture-'.$role.$variant;
            $zip->addFromString('images/'.$role.'.tar', $body);
            $manifest['images'][] = ['role' => $role, 'archive' => 'images/'.$role.'.tar',
                'ref' => 'fixture/'.$role.':1', 'image_id' => 'sha256:'.str_repeat('a', 64), 'sha256' => hash('sha256', $body)];
        }
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();
        try {
            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }

    private function officeRepository(): RepositoryIntegration
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'active' => true]));
        $product = Product::create(['name' => 'Office', 'slug' => 'office', 'status' => 'active']);

        return RepositoryIntegration::create(['product_id' => $product->id, 'provider' => 'github',
            'repository_url' => 'https://github.com/owner/office', 'branch' => 'main',
            'release_channel' => 'stable', 'enabled' => true, 'auto_publish' => true]);
    }

    private function fakeRuntimeDownload(string &$body): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) use (&$body) {
            if (str_contains($request->url(), '/releases/assets/123')) {
                return Http::response($body);
            }

            return Http::response([['tag_name' => 'v3.8.19', 'draft' => false, 'prerelease' => false,
                'body' => 'Security fixes', 'assets' => [['id' => 123, 'name' => 'office-runtime-3.8.19-amd64.zip',
                    'state' => 'uploaded', 'size' => strlen($body), 'digest' => 'sha256:'.hash('sha256', $body)]]]]);
        });
    }

    public function test_deleted_release_is_downloaded_again_and_restored_with_same_identity_and_history(): void
    {
        Storage::fake('packages');
        $integration = $this->officeRepository();
        $body = $this->runtimeZip();
        $this->fakeRuntimeDownload($body);
        $this->post(route('repositories.sync', $integration))->assertSessionHasNoErrors();
        $release = Release::firstOrFail();
        $original = $release->only(['id', 'uuid', 'package_path', 'package_sha256', 'published_at']);
        $this->assertSame('Security fixes', $release->release_notes);
        $this->delete(route('releases.destroy', $release))->assertSessionHasNoErrors();
        Storage::disk('packages')->delete($release->package_path);
        $this->assertSoftDeleted($release);
        $this->post(route('repositories.sync', $integration))->assertSessionHasNoErrors()
            ->assertSessionHas('success', __('ui.repository_restored'));
        $restored = Release::firstOrFail();
        $this->assertEquals($original, $restored->only(array_keys($original)));
        $this->assertSame('published', $restored->status->value);
        $this->assertDatabaseCount('releases', 1);
        $this->assertSame($body, Storage::disk('packages')->get($restored->package_path));
        $this->assertDatabaseHas('audit_logs', ['action' => 'repository.release_restored']);
        $this->assertNull($integration->fresh()->last_error);
        Http::assertSentCount(6);
    }

    public function test_missing_active_package_is_repaired_without_duplicate_release(): void
    {
        Storage::fake('packages');
        $integration = $this->officeRepository();
        $body = $this->runtimeZip();
        $this->fakeRuntimeDownload($body);
        $this->post(route('repositories.sync', $integration))->assertSessionHasNoErrors();
        $release = Release::firstOrFail();
        Storage::disk('packages')->delete($release->package_path);
        $this->post(route('repositories.sync', $integration))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('releases', 1);
        $this->assertSame($body, Storage::disk('packages')->get($release->package_path));
        Http::assertSentCount(6);
    }

    public function test_restoration_preserves_draft_and_does_not_auto_publish_it(): void
    {
        Storage::fake('packages');
        $integration = $this->officeRepository();
        $integration->update(['auto_publish' => false]);
        $body = $this->runtimeZip();
        $this->fakeRuntimeDownload($body);
        $this->post(route('repositories.sync', $integration))->assertSessionHasNoErrors();
        $release = Release::firstOrFail();
        $release->delete();
        $integration->update(['auto_publish' => true]);
        $this->post(route('repositories.sync', $integration))->assertSessionHasNoErrors();
        $this->assertSame('draft', $release->fresh()->status->value);
        $this->assertNull($release->fresh()->published_at);
    }

    public function test_changed_payload_cannot_replace_deleted_version_and_original_file_is_preserved(): void
    {
        Storage::fake('packages');
        $integration = $this->officeRepository();
        $body = $this->runtimeZip();
        $originalBody = $body;
        $this->fakeRuntimeDownload($body);
        $this->post(route('repositories.sync', $integration))->assertSessionHasNoErrors();
        $release = Release::firstOrFail();
        $release->delete();
        $body = $this->runtimeZip('3.8.19', '-changed');
        $this->post(route('repositories.sync', $integration))->assertSessionHasErrors('repository');
        $this->assertSoftDeleted($release);
        $this->assertDatabaseCount('releases', 1);
        $this->assertSame($originalBody, Storage::disk('packages')->get($release->package_path));
        $this->assertSame(__('ui.repository_payload_changed'), $integration->fresh()->last_error);
    }

    public function test_failed_staging_write_does_not_restore_record_or_touch_retained_package(): void
    {
        Storage::fake('packages');
        $integration = $this->officeRepository();
        $body = $this->runtimeZip();
        $this->fakeRuntimeDownload($body);
        $this->post(route('repositories.sync', $integration))->assertSessionHasNoErrors();
        $release = Release::firstOrFail();
        $release->delete();
        $originalDisk = Storage::disk('packages');
        $failedDisk = \Mockery::mock(FilesystemAdapter::class);
        $failedDisk->shouldReceive('put')->once()->withArgs(fn ($path, $stream) => str_starts_with($path, $release->package_path.'.sync-') && is_resource($stream))->andReturn(false);
        $failedDisk->shouldReceive('delete')->once()->andReturn(true);
        $failedDisk->shouldNotReceive('move');
        Storage::shouldReceive('disk')->andReturn($failedDisk);
        $this->post(route('repositories.sync', $integration))->assertSessionHasErrors('repository');
        $this->assertSoftDeleted($release);
        $this->assertSame($body, $originalDisk->get($release->package_path));
        $this->assertDatabaseCount('releases', 1);
    }

    public function test_office_sync_downloads_protected_asset_instead_of_github_source(): void
    {
        Storage::fake('packages');
        $integration = $this->officeRepository();
        $body = $this->runtimeZip();
        Http::preventStrayRequests();
        Http::fake([
            'api.github.com/repos/owner/office/tags?*' => Http::response([]),
            'api.github.com/repos/owner/office/releases?*' => Http::response([
                ['tag_name' => 'v4.0.0-rc.1', 'draft' => false, 'prerelease' => true, 'assets' => []],
                ['tag_name' => 'v3.8.19', 'draft' => false, 'prerelease' => false, 'assets' => [
                    ['id' => 123, 'name' => 'office-runtime-3.8.19-amd64.zip', 'state' => 'uploaded',
                        'size' => strlen($body), 'digest' => 'sha256:'.hash('sha256', $body)],
                ]],
            ]),
            'api.github.com/repos/owner/office/releases/assets/123' => Http::response($body),
        ]);
        $this->post(route('repositories.sync', $integration))->assertRedirect()->assertSessionHasNoErrors();
        $release = Release::firstOrFail();
        $this->assertSame('published', $release->status->value);
        $this->assertSame('3.8.19', $release->runtime_manifest['version']);
        Storage::disk('packages')->assertExists($release->package_path);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/assets/123')
            && $request->hasHeader('Accept', 'application/octet-stream'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'zipball'));
    }

    public function test_invalid_source_fallback_is_not_registered_as_an_update(): void
    {
        $integration = $this->officeRepository();
        Http::preventStrayRequests();
        Http::fake(['api.github.com/repos/owner/office/tags?*' => Http::response([]), 'api.github.com/repos/owner/office/releases?*' => Http::response([
            ['tag_name' => 'v3.8.19', 'draft' => false, 'prerelease' => false, 'assets' => [],
                'zipball_url' => 'https://api.github.com/source.zip'],
        ]), 'api.github.com/source.zip' => Http::response('not-a-zip')]);
        $this->post(route('repositories.sync', $integration))->assertRedirect()->assertSessionHasErrors('repository');
        $this->assertDatabaseCount('releases', 0);
        $this->assertNotNull($integration->fresh()->last_error);
        Http::assertSentCount(3);
    }

    public function test_mismatched_runtime_version_is_not_registered_or_published(): void
    {
        $integration = $this->officeRepository();
        $body = $this->runtimeZip('3.8.18');
        Http::preventStrayRequests();
        Http::fake([
            'api.github.com/repos/owner/office/tags?*' => Http::response([]),
            'api.github.com/repos/owner/office/releases?*' => Http::response([
                ['tag_name' => 'v3.8.19', 'draft' => false, 'prerelease' => false, 'assets' => [
                    ['id' => 456, 'name' => 'office-runtime-3.8.19-amd64.zip', 'state' => 'uploaded', 'size' => strlen($body)],
                ]],
            ]),
            'api.github.com/repos/owner/office/releases/assets/456' => Http::response($body),
        ]);
        $this->post(route('repositories.sync', $integration))->assertRedirect()->assertSessionHasErrors('repository');
        $this->assertDatabaseCount('releases', 0);
    }

    public function test_github_asset_digest_mismatch_is_rejected_before_publication(): void
    {
        $integration = $this->officeRepository();
        $body = $this->runtimeZip();
        Http::preventStrayRequests();
        Http::fake([
            'api.github.com/repos/owner/office/tags?*' => Http::response([]),
            'api.github.com/repos/owner/office/releases?*' => Http::response([
                ['tag_name' => 'v3.8.19', 'draft' => false, 'prerelease' => false, 'assets' => [
                    ['id' => 789, 'name' => 'office-runtime-3.8.19-amd64.zip', 'state' => 'uploaded',
                        'size' => strlen($body), 'digest' => 'sha256:'.str_repeat('0', 64)],
                ]],
            ]),
            'api.github.com/repos/owner/office/releases/assets/789' => Http::response($body),
        ]);
        $this->post(route('repositories.sync', $integration))->assertRedirect()->assertSessionHasErrors('repository');
        $this->assertDatabaseCount('releases', 0);
    }

    public function test_existing_source_release_is_not_silently_reported_ready_for_installer(): void
    {
        $integration = $this->officeRepository();
        $release = Release::create(['product_id' => $integration->product_id, 'version' => '3.8.19',
            'channel' => 'stable', 'status' => 'published', 'source_type' => 'github', 'package_path' => 'source.zip']);
        Http::preventStrayRequests();
        Http::fake(['api.github.com/repos/owner/office/tags?*' => Http::response([]), 'api.github.com/repos/owner/office/releases?*' => Http::response([
            ['tag_name' => 'v3.8.19', 'draft' => false, 'prerelease' => false, 'assets' => []],
        ])]);
        $this->post(route('repositories.sync', $integration))->assertRedirect()->assertSessionHasErrors('repository');
        $this->assertNull($release->fresh()->runtime_manifest);
        $this->assertDatabaseCount('releases', 1);
        Http::assertSentCount(2);
    }

    public function test_non_office_repository_keeps_source_tag_sync_compatibility(): void
    {
        Storage::fake('packages');
        $integration = $this->officeRepository();
        $integration->product->update(['slug' => 'other-product']);
        $path = tempnam(sys_get_temp_dir(), 'repo-source-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('source/artisan', '<?php');
        $zip->close();
        try {
            $body = file_get_contents($path);
        } finally {
            unlink($path);
        }
        Http::preventStrayRequests();
        Http::fake([
            'api.github.com/repos/owner/office/tags?*' => Http::response([
                ['name' => 'v1.0.0', 'zipball_url' => 'https://api.github.com/repos/owner/office/zipball/v1.0.0'],
            ]),
            'api.github.com/repos/owner/office/zipball/v1.0.0' => Http::response($body),
        ]);
        $this->post(route('repositories.sync', $integration))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('1.0.0', Release::firstOrFail()->version);
        $this->assertNull(Release::firstOrFail()->runtime_manifest);
    }
}
