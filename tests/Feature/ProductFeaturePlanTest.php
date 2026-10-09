<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Installation;
use App\Models\License;
use App\Models\Product;
use App\Models\ProductFeature;
use App\Models\Release;
use App\Models\User;
use App\Services\LicenseKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductFeaturePlanTest extends TestCase
{
    use RefreshDatabase;

    private function license(): License
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));
        $customer = Customer::create(['name' => 'Arash', 'company_name' => 'Ponet', 'status' => 'active']);
        $product = Product::create(['name' => 'Office', 'slug' => 'office', 'status' => 'active']);

        return License::create(['customer_id' => $customer->id, 'product_id' => $product->id,
            'license_key_hash' => app(LicenseKeyService::class)->hash('OFF-ABCD-EFGH-2345-6789'),
            'license_key_prefix' => 'OFF-ABCD', 'license_key_encrypted' => 'OFF-ABCD-EFGH-2345-6789',
            'activation_mode' => 'attach_once', 'status' => 'active', 'consumed_at' => now(), 'max_installations' => 1, 'state_revision' => 3]);
    }

    private function feature(Product $product, string $key, array $data = []): ProductFeature
    {
        return ProductFeature::create($data + ['product_id' => $product->id, 'key' => $key, 'name' => ucfirst($key),
            'category' => 'Operations', 'active' => true, 'is_required' => false, 'sort_order' => 0]);
    }

    public function test_catalog_crud_preserves_stable_identity_and_protects_referenced_history(): void
    {
        $license = $this->license();
        $data = ['product_id' => $license->product_id, 'key' => 'projects', 'name' => 'Projects',
            'active' => 1, 'is_required' => 0, 'sort_order' => 5, 'category' => 'Operations'];
        $this->post(route('features.store'), $data)->assertSessionHasNoErrors();
        $feature = ProductFeature::firstOrFail();
        $this->get(route('features.index'))->assertOk()->assertSee('Projects');
        $this->put(route('features.update', $feature), array_replace($data, ['key' => 'new-key']))->assertSessionHasErrors('key');
        $license->features()->attach($feature);
        $license->delete();
        $this->delete(route('features.destroy', $feature))->assertSessionHasErrors('delete');
        $this->put(route('features.update', $feature), array_replace($data, ['active' => 0, 'name' => 'Project management']))->assertSessionHasNoErrors();
        $this->assertFalse($feature->fresh()->active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'feature.updated']);
        $unused = $this->feature($license->product, 'unused');
        $this->delete(route('features.destroy', $unused))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('product_features', ['id' => $unused->id]);
        $this->delete(route('products.destroy', $license->product))->assertSessionHasErrors('delete');
    }

    public function test_selected_plan_includes_required_features_without_resetting_consumed_license_or_installations(): void
    {
        $license = $this->license();
        $core = $this->feature($license->product, 'core', ['is_required' => true]);
        $projects = $this->feature($license->product, 'projects');
        $this->feature($license->product, 'calls');
        $before = $license->only(['license_key_hash', 'license_key_encrypted', 'consumed_at', 'activated_at', 'activation_mode', 'status', 'max_installations', 'uuid']);
        $installation = Installation::create(['license_id' => $license->id, 'customer_id' => $license->customer_id,
            'product_id' => $license->product_id, 'installation_token_hash' => hash('sha256', 'token'),
            'fingerprint' => hash('sha256', 'hardware'), 'hostname' => 'customer-server', 'application_version' => '3.8.25', 'status' => 'active']);
        $this->put(route('licenses.update', $license), ['display_name' => 'Ponet Office', 'edition' => 'Professional',
            'expires_at' => '2030-12-31', 'planned_feature_policy' => 'selected', 'features' => [$projects->id]])->assertSessionHasNoErrors();
        $license->refresh();
        $this->assertEquals($before, $license->only(array_keys($before)));
        $this->assertSame(4, $license->state_revision);
        $this->assertEqualsCanonicalizing([$core->id, $projects->id], $license->plannedFeatures()->pluck('id')->all());
        $this->assertSame('3.8.25', $installation->fresh()->application_version);
        $this->assertSame('active', $installation->fresh()->status->value);
        $this->get(route('licenses.edit', $license))->assertOk()->assertSee('Professional')->assertSee(__('ui.feature_planning_notice'));
        $this->put(route('licenses.update', $license), ['edition' => 'Gold'])->assertSessionHasNoErrors();
        $this->assertTrue($license->fresh()->features->contains($projects));
    }

    public function test_cross_product_and_new_disabled_features_are_rejected_atomically(): void
    {
        $license = $this->license();
        $other = Product::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        $foreign = $this->feature($other, 'projects');
        $disabled = $this->feature($license->product, 'calls', ['active' => false]);
        foreach ([$foreign->id, $disabled->id, 999] as $id) {
            $this->put(route('licenses.update', $license), ['edition' => 'Invalid change', 'planned_feature_policy' => 'selected', 'features' => [$id]])->assertSessionHasErrors('features');
            $this->assertNull($license->fresh()->edition);
            $this->assertSame(3, $license->fresh()->state_revision);
            $this->assertDatabaseCount('license_product_feature', 0);
        }
        $license->features()->attach($disabled);
        $license->update(['planned_feature_policy' => 'selected']);
        $this->put(route('licenses.update', $license), ['planned_feature_policy' => 'selected', 'features' => [$disabled->id]])->assertSessionHasNoErrors();
        $this->assertTrue($license->fresh()->features->contains($disabled));
        $this->assertCount(0, $license->plannedFeatures()->get());
    }

    public function test_create_license_saves_plan_and_default_licenses_keep_all_capabilities(): void
    {
        $seed = $this->license();
        $feature = $this->feature($seed->product, 'projects');
        $this->assertSame('all', $seed->fresh()->planned_feature_policy);
        $this->assertSame([$feature->id], $seed->plannedFeatures()->pluck('id')->all());
        $this->post(route('licenses.store'), ['customer_id' => $seed->customer_id, 'product_id' => $seed->product_id,
            'max_installations' => 1, 'activation_mode' => 'attach_once', 'display_name' => 'New customer',
            'edition' => 'Standard', 'planned_feature_policy' => 'selected', 'features' => [$feature->id]])->assertSessionHasNoErrors();
        $created = License::latest('id')->firstOrFail();
        $this->assertSame('selected', $created->planned_feature_policy);
        $this->assertTrue($created->features->contains($feature));
        $this->assertNull($created->consumed_at);
        $this->assertStringNotContainsString($created->license_key_encrypted, DB::table('audit_logs')->get()->toJson());
    }

    public function test_viewer_cannot_edit_license_or_manage_catalog(): void
    {
        $license = $this->license();
        $feature = $this->feature($license->product, 'projects');
        $this->actingAs(User::factory()->create(['role' => 'viewer', 'active' => true]));
        $this->get(route('features.index'))->assertForbidden();
        $this->post(route('features.store'), [])->assertForbidden();
        $this->delete(route('features.destroy', $feature))->assertForbidden();
        $this->get(route('licenses.edit', $license))->assertForbidden();
        $this->put(route('licenses.update', $license), ['edition' => 'Changed'])->assertForbidden();
        $this->get(route('licenses.index'))->assertOk()->assertDontSee($license->license_key_encrypted);
    }

    public function test_filters_find_customer_company_and_license_and_preserve_combined_criteria(): void
    {
        $license = $this->license();
        $license->update(['display_name' => 'Enterprise branch', 'edition' => 'Gold']);
        $this->get(route('licenses.index', ['q' => 'Ponet', 'status' => 'active']))->assertOk()->assertSee('Enterprise branch');
        $this->get(route('licenses.index', ['q' => 'ponet', 'status' => 'active']))->assertOk()->assertSee('Enterprise branch');
        $this->get(route('licenses.index', ['q' => 'Ponet', 'status' => 'suspended']))->assertOk()->assertDontSee('Enterprise branch');
        $this->get(route('licenses.index', ['q' => $license->license_key_encrypted]))->assertOk()->assertSee('Enterprise branch');
        $this->get(route('customers.index', ['q' => 'Ponet']))->assertOk()->assertSee('Arash');
        $this->get(route('customers.index', ['q' => 'ponet']))->assertOk()->assertSee('Arash');
        $this->get(route('customers.index', ['q' => 'Absent']))->assertOk()->assertDontSee('Arash');
        $feature = $this->feature($license->product, 'projects');
        $this->get(route('features.index', ['q' => 'projects', 'category' => 'Operations']))->assertOk()->assertSee($feature->name);
        $this->get(route('features.index', ['q' => 'projects', 'status' => 'inactive']))->assertOk()->assertDontSee($feature->name);
    }

    public function test_release_details_show_manifest_requirements_and_escape_release_notes(): void
    {
        $license = $this->license();
        $release = Release::create(['product_id' => $license->product_id, 'version' => '3.8.25', 'channel' => 'stable',
            'status' => 'published', 'source_type' => 'manual', 'package_path' => 'runtime.zip',
            'release_notes' => '<script>alert(1)</script>', 'is_security' => true,
            'runtime_manifest' => ['format' => 'office-runtime-v1', 'architecture' => 'amd64', 'source_protection' => 'ioncube',
                'images' => [['role' => 'app', 'ref' => 'office:3.8.25', 'sha256' => str_repeat('a', 64)]]]]);
        $this->get(route('releases.show', $release))->assertOk()->assertSee('amd64')->assertSee('office:3.8.25')
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $this->get(route('releases.index', ['q' => '3.8.25', 'kind' => 'security']))->assertOk()->assertSee('v3.8.25');
        $this->get(route('releases.index', ['q' => '3.8.25', 'kind' => 'source']))->assertOk()->assertDontSee('v3.8.25');
    }
}
