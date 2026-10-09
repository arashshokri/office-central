<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\License;
use App\Models\Product;
use App\Models\Release;
use App\Models\RepositoryIntegration;
use App\Models\User;
use App\Services\OfficeSourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OfficeSourceUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function source(array $extra = [], string $root = 'office-tag/'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'office-source-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $entries = array_fill_keys(OfficeSourceService::REQUIRED, '<?php // fixture');
        $entries['VERSION'] = '3.8.25';
        $entries['Dockerfile'] = 'FROM production AS managed';
        $entries['bootstrap/providers.php'] = '<?php return [OfficeLicenseServiceProvider::class];';
        foreach (array_replace($entries, $extra) as $name => $body) {
            $zip->addFromString($root.$name, $body);
        }
        $zip->close();
        $this->beforeApplicationDestroyed(fn () => is_file($path) ? unlink($path) : null);

        return new UploadedFile($path, 'office-3.8.25.zip', 'application/zip', null, true);
    }

    private function product(): Product
    {
        Storage::fake('packages');
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));

        return Product::create(['slug' => 'office', 'name' => 'Office', 'status' => 'active']);
    }

    public function test_github_source_upload_returns_json_and_published_release_can_be_granted(): void
    {
        $product = $this->product();
        $response = $this->post(route('releases.store'), ['product_id' => $product->id,
            'version' => '3.8.25', 'channel' => 'stable', 'package' => $this->source(['public/vendor/chart.js/library.js' => '/* asset */'])], ['Accept' => 'application/json']);
        $response->assertOk()->assertJsonPath('redirect', route('releases.index'));
        $release = Release::firstOrFail();
        $this->assertSame('office-source-v1', $release->source_manifest['format']);
        $this->assertNull($release->runtime_manifest);
        $this->assertFalse($release->isOfficeUpdateReady());
        $this->post(route('releases.publish', $release))->assertRedirect();
        $this->assertTrue($release->fresh()->isOfficeUpdateReady());
        $customer = Customer::create(['name' => 'Source customer', 'status' => 'active']);
        $license = License::create(['customer_id' => $customer->id, 'product_id' => $product->id,
            'license_key_hash' => hash('sha256', 'source-key'), 'license_key_prefix' => 'OFF-SOURCE',
            'activation_mode' => 'attach_once', 'status' => 'active', 'max_installations' => 1]);
        $this->put(route('licenses.update-release', $license), ['release_id' => $release->id])->assertSessionHasNoErrors();
        $this->assertSame($release->id, $license->fresh()->update_release_id);
        $page = $this->get(route('licenses.show', $license))->assertOk()->assertDontSee(__('ui.no_ready_update_release'));
        $this->assertDoesNotMatchRegularExpression('/<option value="'.$release->id.'"[^>]*disabled/', $page->getContent());
        Storage::disk('packages')->assertExists($release->package_path);
    }

    public function test_invalid_zip_and_source_version_are_validation_errors_instead_of_500(): void
    {
        $product = $this->product();
        $this->post(route('releases.store'), ['product_id' => $product->id, 'version' => '3.8.25',
            'channel' => 'stable', 'package' => $this->source(['../escape.php' => '<?php'])], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('package');
        $this->post(route('releases.store'), ['product_id' => $product->id, 'version' => '3.8.26',
            'channel' => 'stable', 'package' => $this->source()], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertDatabaseCount('releases', 0);
    }

    public function test_missing_helper_and_customer_secrets_are_rejected_before_storage(): void
    {
        $product = $this->product();
        foreach ([['.env' => 'DB_PASSWORD=secret'], ['Dockerfile' => 'FROM production'],
            ['bootstrap/providers.php' => '<?php return [];'], ['vendor/package.php' => '<?php']] as $bad) {
            $this->post(route('releases.store'), ['product_id' => $product->id, 'version' => '3.8.25',
                'channel' => 'stable', 'package' => $this->source($bad)], ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('package');
        }
        $this->assertDatabaseCount('releases', 0);
        $this->assertCount(0, Storage::disk('packages')->allFiles());
    }

    public function test_tag_without_github_release_is_downloaded_and_ready_for_update(): void
    {
        $product = $this->product();
        $source = $this->source();
        $integration = RepositoryIntegration::create(['product_id' => $product->id, 'provider' => 'github',
            'repository_url' => 'https://github.com/owner/office', 'branch' => 'main', 'release_channel' => 'stable',
            'auto_publish' => true, 'enabled' => true]);
        Http::preventStrayRequests();
        Http::fake(['api.github.com/repos/owner/office/releases?*' => Http::response([]),
            'api.github.com/repos/owner/office/tags?*' => Http::response([
                ['name' => 'v3.8.25', 'zipball_url' => 'https://api.github.com/repos/owner/office/zipball/v3.8.25']]),
            'api.github.com/repos/owner/office/zipball/v3.8.25' => Http::response(file_get_contents($source->getRealPath()))]);
        $this->post(route('repositories.sync', $integration))->assertSessionHasNoErrors();
        $release = Release::firstOrFail();
        $this->assertSame('github', $release->source_type->value);
        $this->assertTrue($release->isOfficeUpdateReady());
        $this->assertFalse($release->isOfficeRuntimeReady());
        $this->assertSame('office-tag/', $release->deploymentManifest()['source_root']);
    }

    public function test_github_release_without_runtime_asset_uses_its_validated_source_zip(): void
    {
        $product = $this->product();
        $source = $this->source();
        $integration = RepositoryIntegration::create(['product_id' => $product->id, 'provider' => 'github',
            'repository_url' => 'https://github.com/owner/office', 'branch' => 'main', 'release_channel' => 'stable',
            'auto_publish' => true, 'enabled' => true]);
        Http::preventStrayRequests();
        Http::fake(['api.github.com/repos/owner/office/releases?*' => Http::response([
            ['tag_name' => 'v3.8.25', 'draft' => false, 'prerelease' => false, 'assets' => [],
                'zipball_url' => 'https://api.github.com/repos/owner/office/zipball/v3.8.25']]),
            'api.github.com/repos/owner/office/tags?*' => Http::response([]),
            'api.github.com/repos/owner/office/zipball/v3.8.25' => Http::response(file_get_contents($source->getRealPath()))]);
        $this->post(route('repositories.sync', $integration))->assertSessionHasNoErrors();
        $this->assertTrue(Release::firstOrFail()->isOfficeUpdateReady());
        $this->assertNull(Release::firstOrFail()->runtime_manifest);
    }

    public function test_stable_sync_does_not_import_a_draft_release_through_its_git_tag(): void
    {
        $product = $this->product();
        $integration = RepositoryIntegration::create(['product_id' => $product->id, 'provider' => 'github',
            'repository_url' => 'https://github.com/owner/office', 'branch' => 'main', 'release_channel' => 'stable',
            'auto_publish' => true, 'enabled' => true]);
        Http::preventStrayRequests();
        Http::fake(['api.github.com/repos/owner/office/releases?*' => Http::response([
            ['tag_name' => 'v3.8.25', 'draft' => true, 'prerelease' => false, 'assets' => []]]),
            'api.github.com/repos/owner/office/tags?*' => Http::response([
                ['name' => 'v3.8.25', 'zipball_url' => 'https://api.github.com/repos/owner/office/zipball/v3.8.25']])]);
        $this->post(route('repositories.sync', $integration))->assertSessionHasErrors('repository');
        $this->assertDatabaseCount('releases', 0);
        Http::assertSentCount(2);
    }

    public function test_existing_source_index_is_hash_checked_and_does_not_replace_package(): void
    {
        $product = $this->product();
        $source = $this->source();
        $body = file_get_contents($source->getRealPath());
        Storage::disk('packages')->put('old.zip', $body);
        $release = Release::create(['product_id' => $product->id, 'version' => '3.8.25', 'channel' => 'stable',
            'status' => 'published', 'source_type' => 'github', 'package_path' => 'old.zip', 'package_sha256' => hash('sha256', $body)]);
        $this->artisan('office:index-source-releases')->assertSuccessful();
        $this->assertTrue($release->fresh()->isOfficeUpdateReady());
        $this->assertSame(hash('sha256', $body), $release->fresh()->package_sha256);
        $this->assertSame($body, Storage::disk('packages')->get('old.zip'));
        $bad = Release::create(['product_id' => $product->id, 'version' => '3.8.26', 'channel' => 'stable',
            'status' => 'published', 'source_type' => 'github', 'package_path' => 'old.zip', 'package_sha256' => str_repeat('f', 64)]);
        $this->artisan('office:index-source-releases')->assertSuccessful();
        $this->assertNull($bad->fresh()->source_manifest);
    }

    public function test_fresh_license_accepts_existing_github_source_and_normalizes_public_url(): void
    {
        $product = $this->product();
        $file = $this->source();
        $body = file_get_contents($file->getRealPath());
        Storage::disk('packages')->put('old-source.zip', $body);
        $release = Release::create(['product_id' => $product->id, 'version' => '3.8.25', 'channel' => 'stable',
            'status' => 'published', 'source_type' => 'github', 'package_path' => 'old-source.zip', 'package_sha256' => hash('sha256', $body)]);
        $this->get(route('releases.show', $release))->assertOk()->assertSee(__('ui.ready_for_helper'))->assertDontSee(__('ui.not_ready_for_helper'));
        $this->assertNotNull($release->fresh()->source_manifest);
        $customer = Customer::create(['name' => 'New Office', 'status' => 'active']);
        $this->post(route('licenses.store'), ['activation_mode' => 'installer_once', 'product_id' => $product->id,
            'customer_id' => $customer->id, 'release_id' => $release->id, 'max_installations' => 1,
            'deployment' => ['app_url' => 'http://office2.ponet.ir', 'admin_email' => 'admin@example.test', 'port' => 8082]])
            ->assertSessionHasNoErrors()->assertRedirect();
        $license = License::firstOrFail();
        $this->assertSame('https://office2.ponet.ir', $license->deployment_config['app_url']);
        $this->assertSame(8082, $license->deployment_config['port']);
        $this->assertSame('installer_once', $license->activation_mode);
        $this->assertNull($license->consumed_at);
    }

    public function test_invalid_customer_url_returns_a_localized_error_and_creates_no_license(): void
    {
        $product = $this->product();
        $customer = Customer::create(['name' => 'New Office', 'status' => 'active']);
        $this->post(route('licenses.store'), ['activation_mode' => 'installer_once', 'product_id' => $product->id,
            'customer_id' => $customer->id, 'max_installations' => 1,
            'deployment' => ['app_url' => 'not a domain', 'admin_email' => 'admin@example.test']])
            ->assertSessionHasErrors(['deployment.app_url' => __('ui.customer_url_invalid')]);
        $this->assertDatabaseCount('licenses', 0);
    }
}
