<?php

namespace Tests\Feature;

use App\Models\AgentStateSnapshot;
use App\Models\Customer;
use App\Models\Installation;
use App\Models\License;
use App\Models\Product;
use App\Models\Release;
use App\Models\User;
use App\Services\LicenseKeyService;
use App\Services\SigningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentStateControlTest extends TestCase
{
    use RefreshDatabase;

    private string $key = 'OFF-7KG2-MP9Q-4XAV-HF83';

    private array $hardware = ['machine_id' => 'machine-a', 'product_uuid' => 'product-a', 'system_serial' => 'serial-a'];

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        config(['office.require_https' => false, 'office.public_url' => 'https://panel.ponet.ir', 'office.agent_poll_seconds' => 5]);
        $pair = SigningService::generate();
        $this->publicKey = $pair['public'];
        config(['office.signing_private_key' => $pair['private'], 'office.signing_public_key' => $pair['public']]);
    }

    public function test_admin_temporary_lock_and_unlock_are_delivered_as_newer_signed_states(): void
    {
        $license = $this->seedLicense();
        $activation = $this->postJson('/api/v1/agent/activate', [
            'license_key' => $this->key, 'hostname' => 'office-company', 'hardware' => $this->hardware,
        ])->assertCreated();
        $credential = $activation->json('data.credential');
        $this->assertSigned($activation->json('data.signed_state'));
        $this->assertSame('allowed', $activation->json('data.signed_state.state.access'));

        $unchanged = $this->withHeaders($this->agentHeaders($credential))->postJson('/api/v1/agent/state', [
            'hardware' => $this->hardware,
        ])->assertOk();
        $this->assertSame($activation->json('data.signed_state.state.state_id'), $unchanged->json('data.state.state_id'));
        $this->assertSame(1, AgentStateSnapshot::count());

        $admin = User::factory()->create(['role' => 'super_admin', 'active' => true]);
        $this->actingAs($admin)->post(route('licenses.temporary-lock', $license), [
            'message' => 'برای بررسی با پشتیبانی تماس بگیرید.',
        ])->assertRedirect();

        $locked = $this->withHeaders($this->agentHeaders($credential))->postJson('/api/v1/agent/state', [
            'hardware' => $this->hardware,
        ])->assertOk()->assertJsonPath('data.state.access', 'locked')->assertJsonPath('data.state.code', 'TEMPORARY_LOCK');
        $this->assertSame(2, $locked->json('data.state.state_revision'));
        $this->assertArrayNotHasKey('expires_at', $locked->json('data.state'));
        $this->assertSigned($locked->json('data'));

        $this->actingAs($admin)->delete(route('licenses.temporary-unlock', $license))->assertRedirect();
        $allowed = $this->withHeaders($this->agentHeaders($credential))->postJson('/api/v1/agent/state', [
            'hardware' => $this->hardware,
        ])->assertOk()->assertJsonPath('data.state.access', 'allowed');
        $this->assertSame(3, $allowed->json('data.state.state_revision'));
        $this->assertSigned($allowed->json('data'));
        $this->assertNotNull(Installation::first()->last_state_synced_at);
    }

    public function test_viewer_cannot_change_the_temporary_lock(): void
    {
        $license = $this->seedLicense();
        $viewer = User::factory()->create(['role' => 'viewer', 'active' => true]);

        $this->actingAs($viewer)->post(route('licenses.temporary-lock', $license))->assertForbidden();
        $this->assertNull($license->fresh()->temporarily_locked_at);
    }

    public function test_expiration_invalidates_a_cached_allowed_state_with_a_new_revision(): void
    {
        $license = $this->seedLicense();
        $activation = $this->postJson('/api/v1/agent/activate', [
            'license_key' => $this->key,
            'hostname' => 'office-company',
            'hardware' => $this->hardware,
        ])->assertCreated();
        $credential = $activation->json('data.credential');

        $license->update(['expires_at' => now()->subMinute()]);
        $expired = $this->withHeaders($this->agentHeaders($credential))->postJson('/api/v1/agent/state', [
            'hardware' => $this->hardware,
        ])->assertOk()
            ->assertJsonPath('data.state.access', 'locked')
            ->assertJsonPath('data.state.code', 'LICENSE_EXPIRED');

        $this->assertSame(2, $expired->json('data.state.state_revision'));
        $this->assertSame('expired', $license->fresh()->status->value);
        $this->assertSame(2, AgentStateSnapshot::count());
        $this->assertSigned($expired->json('data'));
    }

    private function seedLicense(): License
    {
        $customer = Customer::create(['name' => 'Customer', 'status' => 'active']);
        $product = Product::create(['name' => 'Office', 'slug' => 'office', 'status' => 'active']);
        $release = Release::create(['product_id' => $product->id, 'version' => '3.6.54', 'channel' => 'stable', 'status' => 'published', 'source_type' => 'manual', 'published_at' => now()]);

        return License::create([
            'license_key_hash' => app(LicenseKeyService::class)->hash($this->key), 'license_key_prefix' => 'OFF-7KG2',
            'customer_id' => $customer->id, 'product_id' => $product->id, 'release_id' => $release->id,
            'status' => 'created', 'state_revision' => 1, 'max_installations' => 1,
        ]);
    }

    private function agentHeaders(string $credential): array
    {
        return ['Authorization' => 'Bearer '.$credential, 'X-Request-Nonce' => (string) Str::uuid(), 'X-Request-Timestamp' => (string) now()->timestamp];
    }

    private function assertSigned(array $signed): void
    {
        $payload = $signed['state'];
        ksort($payload);
        $message = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = sodium_base642bin($signed['signature'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $publicKey = sodium_base642bin($this->publicKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $this->assertTrue(sodium_crypto_sign_verify_detached($signature, $message, $publicKey));
    }
}
