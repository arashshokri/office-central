<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\RepositoryIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
}
