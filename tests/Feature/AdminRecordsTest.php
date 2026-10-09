<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Installation;
use App\Models\License;
use App\Models\Product;
use App\Models\Release;
use App\Models\RepositoryIntegration;
use App\Models\User;
use App\Services\LicenseKeyService;
use App\Services\SigningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'super_admin', 'active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function license(bool $recoverable = true): License
    {
        $customer = Customer::create(['name' => 'Sample customer', 'status' => 'active']);
        $product = Product::create(['name' => 'Office', 'slug' => 'office', 'status' => 'active']);
        $raw = 'OFF-7KG2-MP9Q-4XAV-HF83';

        return License::create(['customer_id' => $customer->id, 'product_id' => $product->id,
            'license_key_hash' => app(LicenseKeyService::class)->hash($raw), 'license_key_prefix' => 'OFF-7KG2',
            'license_key_encrypted' => $recoverable ? $raw : null, 'status' => 'created', 'max_installations' => 1, 'state_revision' => 1]);
    }

    public function test_customer_and_product_forms_edit_existing_values_and_soft_delete_unused_records(): void
    {
        $this->admin();
        $customer = Customer::create(['name' => 'Before customer', 'status' => 'active', 'phone' => '09120000000']);
        $product = Product::create(['name' => 'Before product', 'slug' => 'other', 'status' => 'active']);
        $this->get(route('customers.edit', $customer))->assertOk()->assertSee('09120000000')->assertSee('Before customer');
        $this->get(route('products.edit', $product))->assertOk()->assertSee('Before product');
        $this->put(route('customers.update', $customer), ['name' => 'Updated customer', 'status' => 'inactive'])->assertSessionHasNoErrors();
        $this->put(route('products.update', $product), ['name' => 'Updated product', 'slug' => 'updated', 'status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame('Updated customer', $customer->fresh()->name);
        $this->assertSame('updated', $product->fresh()->slug);
        $this->delete(route('customers.destroy', $customer))->assertRedirect(route('customers.index'));
        $this->delete(route('products.destroy', $product))->assertRedirect(route('products.index'));
        $this->assertSoftDeleted($customer);
        $this->assertSoftDeleted($product);
    }

    public function test_dependencies_block_parent_deletion_and_product_identity_changes(): void
    {
        $this->admin();
        $license = $this->license();
        $this->delete(route('customers.destroy', $license->customer))->assertSessionHasErrors('delete');
        $this->delete(route('products.destroy', $license->product))->assertSessionHasErrors('delete');
        $this->put(route('products.update', $license->product), ['name' => 'Office renamed', 'slug' => 'changed', 'status' => 'active'])->assertSessionHasErrors('slug');
        $this->assertNotSoftDeleted($license->customer);
        $this->assertNotSoftDeleted($license->product);
        $this->assertSame('office', $license->product->fresh()->slug);
    }

    public function test_creation_with_deleted_slug_restores_same_identity_with_submitted_details(): void
    {
        $this->admin();
        $product = Product::create(['name' => 'Old Office', 'slug' => 'office', 'description' => 'Old description', 'status' => 'inactive']);
        $originalUuid = $product->uuid;
        $this->delete(route('products.destroy', $product))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($product);
        $this->post(route('products.store'), ['name' => 'Office', 'slug' => ' office ', 'description' => 'Updated description', 'status' => 'active'])
            ->assertSessionHasNoErrors()->assertRedirect(route('products.index'))->assertSessionHas('success', __('ui.product_restored'));
        $this->assertNotSoftDeleted($product);
        $this->assertSame(1, Product::withTrashed()->count());
        $restored = Product::firstOrFail();
        $this->assertSame($product->id, $restored->id);
        $this->assertSame($originalUuid, $restored->uuid);
        $this->assertSame('Office', $restored->name);
        $this->assertSame('Updated description', $restored->description);
        $this->assertSame('active', $restored->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.restored', 'subject_id' => $product->id]);
    }

    public function test_active_slug_collision_and_renaming_to_deleted_identity_return_localized_errors(): void
    {
        $this->admin();
        $active = Product::create(['name' => 'Office', 'slug' => 'office', 'status' => 'active']);
        $this->post(route('products.store'), ['name' => 'Duplicate', 'slug' => 'office', 'status' => 'active'])
            ->assertSessionHasErrors(['slug' => __('ui.product_slug_taken')]);
        $deleted = Product::create(['name' => 'Deleted', 'slug' => 'deleted', 'status' => 'active']);
        $deleted->delete();
        $this->put(route('products.update', $active), ['name' => 'Renamed', 'slug' => 'deleted', 'status' => 'active'])
            ->assertSessionHasErrors(['slug' => __('ui.product_slug_taken')]);
        $this->assertSame('office', $active->fresh()->slug);
        $this->assertSame('Office', $active->fresh()->name);
        $this->assertSoftDeleted($deleted);
        $this->assertSame(2, Product::withTrashed()->count());
    }

    public function test_restoring_product_does_not_restore_deleted_releases_or_licenses_and_viewer_cannot_restore(): void
    {
        $this->admin();
        $license = $this->license();
        $product = $license->product;
        $release = Release::create(['product_id' => $product->id, 'version' => '3.8.25', 'channel' => 'stable',
            'status' => 'published', 'source_type' => 'github', 'package_path' => 'retained.zip', 'package_sha256' => str_repeat('a', 64)]);
        $license->update(['release_id' => $release->id]);
        $installation = Installation::create(['license_id' => $license->id, 'product_id' => $product->id,
            'customer_id' => $license->customer_id, 'release_id' => $release->id, 'fingerprint' => hash('sha256', 'restored-device'),
            'status' => 'active', 'hostname' => 'existing', 'installation_token_hash' => hash('sha256', 'restored-token')]);
        $this->delete(route('licenses.destroy', $license))->assertSessionHasNoErrors();
        $this->delete(route('releases.destroy', $release))->assertSessionHasNoErrors();
        $this->delete(route('products.destroy', $product))->assertSessionHasNoErrors();
        $this->actingAs(User::factory()->create(['role' => 'viewer', 'active' => true]))
            ->post(route('products.store'), ['name' => 'Denied', 'slug' => 'office', 'status' => 'active'])->assertForbidden();
        $this->assertSoftDeleted($product);
        $this->admin();
        $this->post(route('products.store'), ['name' => 'Restored Office', 'slug' => 'office', 'status' => 'active'])->assertSessionHasNoErrors();
        $this->assertNotSoftDeleted($product);
        $this->assertSoftDeleted($license);
        $this->assertSoftDeleted($release);
        $this->assertSame('revoked', License::withTrashed()->findOrFail($license->id)->status->value);
        $this->assertSame(2, License::withTrashed()->findOrFail($license->id)->state_revision);
        $this->assertSame($product->id, $installation->fresh()->product_id);
        $this->assertSame($release->id, $installation->fresh()->release_id);
        $this->assertSame('retained.zip', Release::withTrashed()->findOrFail($release->id)->package_path);
    }

    public function test_new_codes_are_encrypted_recoverable_and_never_serialized_or_audited(): void
    {
        $this->admin();
        $seed = $this->license(false);
        $this->post(route('licenses.store'), ['customer_id' => $seed->customer_id, 'product_id' => $seed->product_id, 'max_installations' => 1])->assertSessionHasNoErrors();
        $license = License::latest('id')->firstOrFail();
        $raw = $license->license_key_encrypted;
        $this->assertNotEmpty($raw);
        $this->assertSame(app(LicenseKeyService::class)->hash($raw), $license->license_key_hash);
        $stored = DB::table('licenses')->where('id', $license->id)->value('license_key_encrypted');
        $this->assertStringNotContainsString($raw, $stored);
        $this->assertArrayNotHasKey('license_key_encrypted', $license->toArray());
        $this->assertArrayNotHasKey('license_key_hash', $license->toArray());
        $this->get(route('licenses.show', $license))->assertOk()->assertSee($raw)->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('licenses.index'))->assertOk()->assertSee($raw);
        $this->post(route('licenses.status', [$license, 'suspended']))->assertRedirect();
        $this->assertStringNotContainsString($raw, DB::table('audit_logs')->get()->toJson());
    }

    public function test_viewer_cannot_see_codes_or_mutate_records(): void
    {
        $license = $this->license();
        $raw = $license->license_key_encrypted;
        $this->actingAs(User::factory()->create(['role' => 'viewer', 'active' => true]));
        $this->get(route('licenses.show', $license))->assertOk()->assertDontSee($raw);
        $this->get(route('licenses.index'))->assertOk()->assertDontSee($raw)->assertDontSee('name="_method" value="DELETE"', false);
        foreach (['customers' => $license->customer, 'products' => $license->product, 'licenses' => $license] as $name => $model) {
            $this->delete(route($name.'.destroy', $model))->assertForbidden();
        }
        $this->post(route('licenses.replace-code', $license))->assertForbidden();
        $this->get(route('users.index'))->assertForbidden();
    }

    public function test_legacy_code_replacement_preserves_consumption_expiry_and_installation_binding(): void
    {
        $this->admin();
        $license = $this->license(false);
        $license->update(['activation_mode' => 'attach_once', 'status' => 'active', 'consumed_at' => now()->subDay(), 'expires_at' => now()->addYear()]);
        $installation = Installation::create(['license_id' => $license->id, 'customer_id' => $license->customer_id, 'product_id' => $license->product_id, 'fingerprint' => hash('sha256', 'device'), 'status' => 'active', 'hostname' => 'existing', 'installation_token_hash' => hash('sha256', 'credential')]);
        $before = $license->fresh();
        $oldHash = $license->license_key_hash;
        $this->post(route('licenses.replace-code', $license))->assertRedirect()->assertSessionHasNoErrors();
        $after = $license->fresh();
        $this->assertNotSame($oldHash, $after->license_key_hash);
        $this->assertNotEmpty($after->license_key_encrypted);
        $this->assertTrue($before->consumed_at->equalTo($after->consumed_at));
        $this->assertTrue($before->expires_at->equalTo($after->expires_at));
        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->state_revision, $after->state_revision);
        $this->assertSame($license->id, $installation->fresh()->license_id);
        $this->assertSame(hash('sha256', 'credential'), $installation->fresh()->installation_token_hash);
        $this->post(route('licenses.replace-code', $license))->assertStatus(409);
    }

    public function test_deleted_license_delivers_signed_revocation_and_retains_installations_and_parent_history(): void
    {
        $license = $this->license();
        $pair = SigningService::generate();
        config(['office.require_https' => false, 'office.signing_private_key' => $pair['private'], 'office.signing_public_key' => $pair['public']]);
        $hardware = ['machine_id' => 'machine-a', 'product_uuid' => 'product-a', 'system_serial' => 'serial-a'];
        $activation = $this->postJson('/api/v1/agent/activate', ['license_key' => $license->license_key_encrypted, 'hostname' => 'existing', 'hardware' => $hardware])->assertCreated();
        $credential = $activation->json('data.credential');
        $this->admin();
        $this->delete(route('licenses.destroy', $license))->assertRedirect();
        $this->assertSoftDeleted($license);
        $this->assertSame(1, Installation::count());
        $this->delete(route('customers.destroy', $license->customer))->assertRedirect()->assertSessionHasNoErrors();
        $this->delete(route('products.destroy', $license->product))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('products.store'), ['name' => 'Office restored', 'slug' => 'office', 'status' => 'active'])
            ->assertSessionHasNoErrors();
        $this->assertNotSoftDeleted($license->product);
        $state = $this->withHeaders(['Authorization' => 'Bearer '.$credential, 'X-Request-Nonce' => (string) Str::uuid(), 'X-Request-Timestamp' => (string) now()->timestamp])
            ->postJson('/api/v1/agent/state', ['hardware' => $hardware])->assertOk()
            ->assertJsonPath('data.state.access', 'locked')->assertJsonPath('data.state.code', 'LICENSE_REVOKED');
        $this->assertNotEmpty($state->json('data.signature'));
        $this->assertSame(2, $state->json('data.state.state_revision'));
        $this->assertSame($license->id, Installation::first()->license->id);
        $this->get(route('installations.show', Installation::first()))->assertOk();
    }

    public function test_published_release_allows_notes_but_rejects_identity_changes_and_delete_when_assigned(): void
    {
        $this->admin();
        $license = $this->license();
        $release = Release::create(['product_id' => $license->product_id, 'version' => '1.0.0', 'channel' => 'stable', 'status' => 'published', 'source_type' => 'manual', 'package_path' => 'packages/keep.zip']);
        $this->get(route('releases.edit', $release))->assertOk()->assertSee(__('ui.published_release_immutable'));
        $this->put(route('releases.update', $release), ['release_notes' => 'Security fixes', 'is_security' => true])->assertSessionHasNoErrors();
        $this->assertTrue($release->fresh()->is_security);
        $this->put(route('releases.update', $release), ['release_notes' => 'Revised notes'])->assertSessionHasNoErrors();
        $this->assertTrue($release->fresh()->is_security);
        $this->put(route('releases.update', $release), ['version' => '2.0.0'])->assertSessionHasErrors('version');
        $license->update(['release_id' => $release->id]);
        $this->delete(route('releases.destroy', $release))->assertSessionHasErrors('delete');
        $license->update(['release_id' => null]);
        Storage::fake('packages');
        Storage::disk('packages')->put('packages/keep.zip', 'fixture');
        $this->delete(route('releases.destroy', $release))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($release);
        Storage::disk('packages')->assertExists('packages/keep.zip');
    }

    public function test_draft_release_can_edit_metadata_without_reupload_and_duplicate_version_is_validation_error(): void
    {
        $this->admin();
        $product = Product::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        $release = Release::create(['product_id' => $product->id, 'version' => '1.0.0', 'channel' => 'stable', 'status' => 'draft', 'source_type' => 'manual', 'package_path' => 'keep.zip']);
        Release::create(['product_id' => $product->id, 'version' => '2.0.0', 'channel' => 'stable', 'status' => 'draft', 'source_type' => 'manual']);
        $this->put(route('releases.update', $release), ['product_id' => $product->id, 'version' => '1.0.1', 'channel' => 'stable', 'release_notes' => 'Edited'])->assertSessionHasNoErrors();
        $this->assertSame('keep.zip', $release->fresh()->package_path);
        $this->assertSame('1.0.1', $release->fresh()->version);
        $this->put(route('releases.update', $release), ['product_id' => $product->id, 'version' => '2.0.0', 'channel' => 'stable'])->assertSessionHasErrors('version');
    }

    public function test_repository_simple_defaults_edit_preserves_token_and_disconnect_preserves_releases(): void
    {
        $this->admin();
        $product = Product::create(['name' => 'Office', 'slug' => 'office', 'status' => 'active']);
        $this->post(route('repositories.store'), ['product_id' => $product->id, 'repository_url' => 'owner/office.git', 'access_token' => 'fake-token'])->assertSessionHasNoErrors();
        $integration = RepositoryIntegration::firstOrFail();
        $this->assertSame('https://github.com/owner/office', $integration->repository_url);
        $this->assertSame('main', $integration->branch);
        $this->assertSame('stable', $integration->release_channel);
        $this->get(route('repositories.index'))->assertOk()->assertDontSee('fake-token')->assertSee(__('ui.test_connection'));
        $this->get(route('repositories.edit', $integration))->assertOk()->assertDontSee('fake-token');
        $this->put(route('repositories.update', $integration), ['product_id' => $product->id, 'repository_url' => $integration->repository_url, 'access_token' => ''])->assertSessionHasNoErrors();
        $this->assertSame('fake-token', $integration->fresh()->encrypted_access_token);
        $this->post(route('repositories.store'), ['product_id' => $product->id, 'repository_url' => $integration->repository_url])->assertSessionHasErrors('repository_url');
        $release = Release::create(['product_id' => $product->id, 'version' => '1.0.0', 'channel' => 'stable', 'status' => 'draft', 'source_type' => 'github']);
        $this->delete(route('repositories.destroy', $integration))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('repository_integrations', ['id' => $integration->id]);
        $this->assertNotSoftDeleted($release);
    }

    public function test_repository_connection_check_uses_saved_token_and_gives_friendly_errors_without_importing(): void
    {
        $this->admin();
        $product = Product::create(['name' => 'Office', 'slug' => 'office', 'status' => 'active']);
        $integration = RepositoryIntegration::create(['product_id' => $product->id, 'provider' => 'github', 'repository_url' => 'https://github.com/owner/private', 'branch' => 'main', 'release_channel' => 'stable', 'enabled' => true, 'encrypted_access_token' => 'fake-token']);
        Http::preventStrayRequests();
        Http::fake(['api.github.com/*' => Http::sequence()->push(['full_name' => 'owner/private'])->push(['message' => 'Not Found'], 404)]);
        $this->post(route('repositories.test', $integration))->assertSessionHasNoErrors()->assertSessionHas('success', __('ui.repository_connection_ok'));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer fake-token'));
        $this->post(route('repositories.test', $integration))->assertSessionHasErrors(['repository' => __('ui.repository_not_found')]);
        $this->assertDatabaseCount('releases', 0);
    }

    public function test_user_editor_changes_identity_and_password_and_guards_last_super_admin(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['role' => 'viewer', 'active' => true]);
        $this->get(route('users.index'))->assertOk()->assertSee(__('ui.admin_users'))->assertSee(__('ui.create_user'));
        $this->get(route('users.create'))->assertOk();
        $this->get(route('users.edit', $user))->assertOk()->assertSee($user->email);
        $this->put(route('users.update', $user), ['name' => 'Updated user', 'email' => ' NEW@EXAMPLE.COM ', 'password' => 'new-password-1234', 'password_confirmation' => 'new-password-1234', 'role' => 'admin', 'active' => 1])->assertSessionHasNoErrors();
        $this->assertSame('new@example.com', $user->fresh()->email);
        $this->assertSame('Updated user', $user->fresh()->name);
        $this->assertTrue(Hash::check('new-password-1234', $user->fresh()->password));
        $hash = $user->fresh()->password;
        $this->put(route('users.update', $user), ['role' => 'viewer', 'active' => 1, 'password' => ''])->assertSessionHasNoErrors();
        $this->assertSame($hash, $user->fresh()->password);
        $this->put(route('users.update', $admin), ['role' => 'admin', 'active' => 1])->assertSessionHasErrors('role');
        $this->put(route('users.update', $admin), ['role' => 'super_admin', 'active' => 0])->assertSessionHasErrors('role');
        $this->assertTrue($admin->fresh()->active);
    }
}
