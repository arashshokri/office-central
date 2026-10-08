<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\RepositoryIntegration;
use App\Models\Release;
use App\Models\User;
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

    private function runtimeZip(string $version = '3.8.19'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'repo-runtime-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $manifest = ['format' => 'office-runtime-v1', 'product' => 'office', 'version' => $version,
            'source_protection' => 'ioncube', 'architecture' => 'amd64', 'images' => []];
        foreach (['app', 'db', 'redis', 'rdp-web', 'rdp-core'] as $role) {
            $body = 'fixture-'.$role;
            $zip->addFromString('images/'.$role.'.tar', $body);
            $manifest['images'][] = ['role' => $role, 'archive' => 'images/'.$role.'.tar',
                'ref' => 'fixture/'.$role.':1', 'image_id' => 'sha256:'.str_repeat('a', 64), 'sha256' => hash('sha256', $body)];
        }
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();
        try { return file_get_contents($path); } finally { unlink($path); }
    }

    private function officeRepository(): RepositoryIntegration
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'active' => true]));
        $product = Product::create(['name' => 'Office', 'slug' => 'office', 'status' => 'active']);
        return RepositoryIntegration::create(['product_id' => $product->id, 'provider' => 'github',
            'repository_url' => 'https://github.com/owner/office', 'branch' => 'main',
            'release_channel' => 'stable', 'enabled' => true, 'auto_publish' => true]);
    }

    public function test_office_sync_downloads_protected_asset_instead_of_github_source(): void
    {
        Storage::fake('packages');
        $integration = $this->officeRepository();
        $body = $this->runtimeZip();
        Http::preventStrayRequests();
        Http::fake([
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
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'zipball') || str_contains($request->url(), '/tags'));
    }

    public function test_missing_runtime_asset_does_not_fall_back_to_source_archive(): void
    {
        $integration = $this->officeRepository();
        Http::preventStrayRequests();
        Http::fake(['api.github.com/repos/owner/office/releases?*' => Http::response([
            ['tag_name' => 'v3.8.19', 'draft' => false, 'prerelease' => false, 'assets' => [],
                'zipball_url' => 'https://api.github.com/source.zip'],
        ])]);
        $this->post(route('repositories.sync', $integration))->assertRedirect()->assertSessionHasErrors('repository');
        $this->assertDatabaseCount('releases', 0);
        $this->assertStringContainsString('office-runtime-3.8.19-amd64.zip', $integration->fresh()->last_error);
        Http::assertSentCount(1);
    }

    public function test_mismatched_runtime_version_is_not_registered_or_published(): void
    {
        $integration = $this->officeRepository();
        $body = $this->runtimeZip('3.8.18');
        Http::preventStrayRequests();
        Http::fake([
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
        Http::fake(['api.github.com/repos/owner/office/releases?*' => Http::response([
            ['tag_name' => 'v3.8.19', 'draft' => false, 'prerelease' => false, 'assets' => []],
        ])]);
        $this->post(route('repositories.sync', $integration))->assertRedirect()->assertSessionHasErrors('repository');
        $this->assertNull($release->fresh()->runtime_manifest);
        $this->assertDatabaseCount('releases', 1);
        Http::assertSentCount(1);
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
        try { $body = file_get_contents($path); } finally { unlink($path); }
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
