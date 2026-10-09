<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Release;
use App\Models\User;
use App\Services\OfficeSourceService;
use App\Services\ReleaseUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReleaseChunkUploadTest extends TestCase
{
    use RefreshDatabase;

    private function setupUpload(): array
    {
        Storage::fake('local');
        Storage::fake('packages');
        $user = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($user);
        $product = Product::create(['slug' => 'customer-office', 'name' => 'Office', 'status' => 'active']);
        $path = tempnam(sys_get_temp_dir(), 'chunk-zip-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $entries = array_fill_keys(OfficeSourceService::REQUIRED, '<?php // fixture');
        $entries['VERSION'] = '3.8.25';
        $entries['Dockerfile'] = 'FROM production AS managed';
        $entries['bootstrap/providers.php'] = 'OfficeLicenseServiceProvider';
        $entries['public/large.bin'] = random_bytes(600000);
        foreach ($entries as $name => $value) {
            $zip->addFromString('office-3.8.25/'.$name, $value);
        }
        $zip->close();
        $body = file_get_contents($path);
        unlink($path);
        $id = $this->postJson(route('release-uploads.store'), ['filename' => 'office.zip', 'size' => strlen($body)])
            ->assertOk()->json('id');

        return [$id, $body, $product, $user];
    }

    private function chunk(string $id, string $body, int $offset)
    {
        return $this->postJson(route('release-uploads.chunk', $id), ['offset' => $offset,
            'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', $body)]);
    }

    public function test_chunked_upload_retries_lost_ack_and_final_save_without_duplicate_release(): void
    {
        [$id, $body, $product] = $this->setupUpload();
        $first = substr($body, 0, ReleaseUploadService::CHUNK_BYTES);
        $this->chunk($id, $first, 0)->assertOk()->assertJsonPath('offset', strlen($first));
        $this->chunk($id, $first, 0)->assertOk()->assertJsonPath('offset', strlen($first));
        for ($offset = strlen($first); $offset < strlen($body); $offset += ReleaseUploadService::CHUNK_BYTES) {
            $bytes = substr($body, $offset, ReleaseUploadService::CHUNK_BYTES);
            $this->chunk($id, $bytes, $offset)->assertOk()->assertJsonPath('offset', $offset + strlen($bytes));
        }
        $data = ['package_upload' => $id, 'product_id' => $product->id, 'version' => '3.8.25', 'channel' => 'stable'];
        $this->postJson(route('releases.store'), $data)->assertOk()->assertJsonPath('redirect', route('releases.index'));
        $this->postJson(route('releases.store'), $data)->assertOk();
        $this->assertDatabaseCount('releases', 1);
        $release = Release::firstOrFail();
        $this->assertSame(hash('sha256', $body), $release->package_sha256);
        $this->assertSame($body, Storage::disk('packages')->get($release->package_path));
        $this->assertSame('office-source-v1', $release->source_manifest['format']);
        Storage::disk('local')->assertMissing('release-uploads/'.$id.'/payload');
    }

    public function test_incomplete_conflicting_out_of_order_and_cross_owner_requests_are_rejected(): void
    {
        [$id, $body, $product, $user] = $this->setupUpload();
        $data = ['package_upload' => $id, 'product_id' => $product->id, 'version' => '3.8.25', 'channel' => 'stable'];
        $this->postJson(route('releases.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('package');
        $this->chunk($id, 'gap', 50)->assertConflict();
        $this->chunk($id, substr($body, 0, 100), 0)->assertOk();
        $this->chunk($id, str_repeat('x', 100), 0)->assertConflict();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));
        $this->chunk($id, 'foreign', 100)->assertNotFound();
        $this->postJson(route('releases.store'), $data)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'viewer', 'active' => true]));
        $this->postJson(route('release-uploads.store'), ['filename' => 'office.zip', 'size' => 10])->assertForbidden();
        $this->actingAs($user);
        $this->assertSame(substr($body, 0, 100), Storage::disk('local')->get('release-uploads/'.$id.'/payload'));
        $this->assertDatabaseCount('releases', 0);
    }

    public function test_size_filename_and_pending_limits_and_expired_cleanup(): void
    {
        [$id] = $this->setupUpload();
        foreach (['../office.zip', 'not-a-zip.txt', 'dir\\office.zip'] as $filename) {
            $this->postJson(route('release-uploads.store'), ['filename' => $filename, 'size' => 10])->assertUnprocessable();
        }
        $this->postJson(route('release-uploads.store'), ['filename' => 'office.zip', 'size' => ReleaseUploadService::MAX_BYTES + 1])->assertUnprocessable();
        $this->chunk($id, str_repeat('x', ReleaseUploadService::CHUNK_BYTES + 1), 0)->assertUnprocessable();
        $this->postJson(route('release-uploads.store'), ['filename' => 'two.zip', 'size' => 10])->assertOk();
        $this->postJson(route('release-uploads.store'), ['filename' => 'three.zip', 'size' => 10])->assertOk();
        $this->postJson(route('release-uploads.store'), ['filename' => 'four.zip', 'size' => 10])->assertUnprocessable();
        $disk = Storage::disk('local');
        $path = 'release-uploads/'.$id.'/metadata.json';
        $metadata = json_decode($disk->get($path), true);
        $metadata['expires'] = time() - 1;
        $disk->put($path, json_encode($metadata));
        $this->chunk($id, 'data', 0)->assertUnprocessable();
        app(ReleaseUploadService::class)->prune();
        $disk->assertMissing($path);
        $this->postJson(route('release-uploads.store'), ['filename' => 'four.zip', 'size' => 10])->assertOk();
    }

    public function test_final_validation_cannot_be_bypassed_and_can_be_corrected_without_reupload(): void
    {
        [$id, $body, $product] = $this->setupUpload();
        for ($offset = 0; $offset < strlen($body); $offset += ReleaseUploadService::CHUNK_BYTES) {
            $this->chunk($id, substr($body, $offset, ReleaseUploadService::CHUNK_BYTES), $offset)->assertOk();
        }
        $data = ['package_upload' => $id, 'product_id' => $product->id, 'version' => '3.8.26', 'channel' => 'stable'];
        $this->postJson(route('releases.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertDatabaseCount('releases', 0);
        $this->postJson(route('releases.store'), array_replace($data, ['version' => '3.8.25']))->assertOk();
        $release = Release::firstOrFail();
        $this->post(route('releases.publish', $release))->assertRedirect();
        $this->postJson(route('release-uploads.store'), ['filename' => 'office.zip', 'size' => 10, 'release_id' => $release->id])->assertUnprocessable();
    }

    public function test_discard_frees_pending_quota_and_session_cannot_be_used_for_another_edit_target(): void
    {
        [$id, $body, $product, $user] = $this->setupUpload();
        $draft = Release::create(['product_id' => $product->id, 'version' => '3.8.25', 'channel' => 'stable', 'status' => 'draft', 'source_type' => 'manual']);
        $this->putJson(route('releases.update', $draft), ['package_upload' => $id, 'product_id' => $product->id,
            'version' => '3.8.25', 'channel' => 'stable'])->assertConflict();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));
        $this->deleteJson(route('release-uploads.destroy', $id))->assertNotFound();
        $this->actingAs($user)->deleteJson(route('release-uploads.destroy', $id))->assertOk();
        $this->chunk($id, substr($body, 0, 100), 0)->assertUnprocessable();
        $this->postJson(route('release-uploads.store'), ['filename' => 'replacement.zip', 'size' => 10])->assertOk();
        Storage::disk('local')->assertMissing('release-uploads/'.$id.'/metadata.json');
    }

    public function test_unacknowledged_partial_write_is_recovered_before_retry(): void
    {
        [$id, $body] = $this->setupUpload();
        Storage::disk('local')->put('release-uploads/'.$id.'/payload', 'partial write from a stopped worker');
        $bytes = substr($body, 0, ReleaseUploadService::CHUNK_BYTES);
        $this->chunk($id, $bytes, 0)->assertOk()->assertJsonPath('offset', strlen($bytes));
        $this->assertSame($bytes, Storage::disk('local')->get('release-uploads/'.$id.'/payload'));
    }
}
