<?php

namespace Tests\Feature;

use App\Models\{Customer, License, Product, Release, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallerAdminWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_page_is_not_shadowed_by_license_binding_and_explains_missing_package(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($admin)->get('/licenses/create')->assertOk()
            ->assertSee(__('ui.helper_simple_help'))
            ->assertSee('deployment[app_url]', false);
        $viewer = User::factory()->create(['role' => 'viewer', 'active' => true]);
        $this->actingAs($viewer)->get('/licenses/create')->assertForbidden();
    }

    public function test_existing_office_license_can_be_issued_without_any_release_or_deployment_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $customer = Customer::create(['name' => 'Existing customer', 'status' => 'active']);
        $product = Product::create(['name' => 'Office', 'slug' => 'office', 'status' => 'active']);
        $this->actingAs($admin)->post('/licenses', ['activation_mode' => 'attach_once', 'customer_id' => $customer->id,
            'product_id' => $product->id, 'max_installations' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $license = License::firstOrFail();
        $this->assertSame('attach_once', $license->activation_mode);
        $this->assertNull($license->release_id);
        $this->get(route('licenses.show', $license))->assertOk()->assertSee(__('ui.attach_preserves_data'));
    }

    public function test_source_archive_cannot_issue_installer_code_and_returns_actionable_form_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $customer = Customer::create(['name' => 'Customer', 'status' => 'active']);
        $product = Product::create(['name' => 'Office', 'slug' => 'office', 'status' => 'active']);
        $release = Release::create(['product_id' => $product->id, 'version' => '3.8.19', 'channel' => 'stable',
            'status' => 'published', 'source_type' => 'manual', 'package_path' => 'source.zip',
            'package_sha256' => str_repeat('a', 64), 'published_at' => now()]);
        $this->actingAs($admin)->from('/licenses/create')->post('/licenses', [
            'activation_mode' => 'installer_once', 'customer_id' => $customer->id, 'product_id' => $product->id,
            'release_id' => $release->id, 'max_installations' => 1,
            'deployment' => ['app_url' => 'https://office.customer.ir', 'admin_email' => 'admin@customer.ir'],
        ])->assertRedirect('/licenses/create')->assertSessionHasErrors(['release_id']);
        $this->assertSame(0, License::count());
    }

    public function test_creation_page_renders_published_source_and_runtime_release_choices(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $customer = Customer::create(['name' => 'Customer form fixture', 'status' => 'active']);
        $product = Product::create(['name' => 'Office form fixture', 'slug' => 'office', 'status' => 'active']);
        foreach (['3.8.19' => null, '3.8.20' => ['version' => '3.8.20']] as $version => $manifest) {
            Release::create(['product_id' => $product->id, 'version' => $version, 'channel' => 'stable',
                'status' => 'published', 'source_type' => 'manual', 'runtime_manifest' => $manifest,
                'package_path' => $version.'.zip', 'package_sha256' => str_repeat('a', 64)]);
        }

        $this->actingAs($admin)->get('/licenses/create')->assertOk()
            ->assertSee($customer->name)->assertSee($product->name)
            ->assertSee('3.8.19')->assertSee('3.8.20')
            ->assertSee(__('ui.source_archive'))->assertSee(__('ui.protected_runtime'))
            ->assertDontSee(__('ui.protected_release_required'));
    }

    public function test_installer_license_shows_safe_customer_command_and_consumed_code_guidance(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $customer = Customer::create(['name' => 'Customer', 'status' => 'active']);
        $product = Product::create(['name' => 'Office', 'slug' => 'office', 'status' => 'active']);
        $license = License::create(['customer_id' => $customer->id, 'product_id' => $product->id,
            'activation_mode' => 'installer_once', 'license_key_hash' => hash('sha256', 'private fixture code'),
            'license_key_prefix' => 'OFF-TEST', 'status' => 'created', 'max_installations' => 1]);
        $this->actingAs($admin)->get(route('licenses.show', $license))->assertOk()
            ->assertSee('https://update.ponet.ir/agent/install.sh')->assertDontSee('private fixture code');
        $license->update(['consumed_at' => now()]);
        $this->get(route('licenses.show', $license))->assertOk()
            ->assertSee(__('ui.installer_consumed_help'))->assertDontSee('id="officeInstallerCommand"', false);
    }
}
