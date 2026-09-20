<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Installation;
use App\Models\License;
use App\Models\Product;
use App\Models\Release;
use App\Services\LicenseKeyService;
use App\Services\SigningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LicenseLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private string $key = 'OFF-7KG2-MP9Q-4XAV-HF83';

    private array $hardwareA = ['machine_id' => 'machine-a', 'product_uuid' => 'product-a', 'system_serial' => 'serial-a'];

    private array $hardwareB = ['machine_id' => 'machine-b', 'product_uuid' => 'product-b', 'system_serial' => 'serial-b'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['office.require_https' => false]);
        $pair = SigningService::generate();
        config(['office.signing_private_key' => $pair['private'], 'office.signing_public_key' => $pair['public']]);
    }

    private function seedDomain(int $limit = 1, string $status = 'created', ?string $expires = null): License
    {
        $customer = Customer::create(['name' => 'Acme', 'status' => 'active']);
        $product = Product::create(['name' => 'Office', 'slug' => 'office', 'status' => 'active']);
        $release = Release::create(['product_id' => $product->id, 'version' => '3.2.4', 'channel' => 'stable', 'status' => 'published', 'source_type' => 'manual', 'published_at' => now()]);

        return License::create(['license_key_hash' => app(LicenseKeyService::class)->hash($this->key), 'license_key_prefix' => 'OFF-7KG2', 'customer_id' => $customer->id, 'product_id' => $product->id, 'release_id' => $release->id, 'status' => $status, 'max_installations' => $limit, 'expires_at' => $expires]);
    }

    private function activate(array $hardware)
    {
        return $this->postJson('/api/v1/agent/activate', ['license_key' => $this->key, 'hostname' => 'office-prod', 'fingerprint_version' => 1, 'hardware' => $hardware, 'os' => ['name' => 'Ubuntu', 'version' => '24.04']]);
    }

    public function test_activation_returns_one_time_credential_and_signed_state(): void
    {
        $this->seedDomain();
        $response = $this->activate($this->hardwareA)->assertCreated()->assertJsonPath('success', true)->assertJsonPath('data.signed_state.algorithm', 'Ed25519')->assertJsonPath('data.signed_state.state.offline_policy', 'keep_last_signed_state_indefinitely');
        $this->assertNotEmpty($response->json('data.credential'));
        $this->assertNull($response->json('data.signed_state.state.expires_at'));
        $this->assertDatabaseMissing('licenses', ['license_key_hash' => $this->key]);
        $this->assertDatabaseCount('installations', 1);
    }

    public function test_invalid_suspended_revoked_and_expired_licenses_are_authoritative(): void
    {
        $this->activate($this->hardwareA)->assertNotFound()->assertJsonPath('code', 'LICENSE_NOT_FOUND');
        $license = $this->seedDomain(1, 'suspended');
        $this->activate($this->hardwareA)->assertForbidden()->assertJsonPath('code', 'LICENSE_SUSPENDED');
        $license->update(['status' => 'revoked']);
        $this->activate($this->hardwareA)->assertForbidden()->assertJsonPath('code', 'LICENSE_REVOKED');
        $license->update(['status' => 'created', 'expires_at' => now()->subDay()]);
        $this->activate($this->hardwareA)->assertForbidden()->assertJsonPath('code', 'LICENSE_EXPIRED');
    }

    public function test_installation_limit_is_enforced(): void
    {
        $this->seedDomain();
        $this->activate($this->hardwareA)->assertCreated();
        $this->activate($this->hardwareB)->assertStatus(409)->assertJsonPath('code', 'LICENSE_INSTALLATION_LIMIT');
    }

    public function test_clone_is_denied_and_original_remains_active(): void
    {
        $this->seedDomain();
        $credential = $this->activate($this->hardwareA)->json('data.credential');
        $headers = ['Authorization' => 'Bearer '.$credential, 'X-Request-Nonce' => (string) Str::uuid(), 'X-Request-Timestamp' => (string) now()->timestamp];
        $this->withHeaders($headers)->postJson('/api/v1/installations/heartbeat', ['hardware' => $this->hardwareB])->assertForbidden()->assertJsonPath('code', 'LICENSE_HARDWARE_MISMATCH');
        $this->assertSame('active', Installation::first()->status->value);
        $this->assertDatabaseHas('security_events', ['type' => 'clone_detected']);
    }

    public function test_replayed_nonce_is_rejected(): void
    {
        $this->seedDomain();
        $credential = $this->activate($this->hardwareA)->json('data.credential');
        $headers = ['Authorization' => 'Bearer '.$credential, 'X-Request-Nonce' => 'fixed-nonce', 'X-Request-Timestamp' => (string) now()->timestamp];
        $this->withHeaders($headers)->postJson('/api/v1/installations/heartbeat', ['hardware' => $this->hardwareA])->assertOk();
        $this->withHeaders($headers)->postJson('/api/v1/installations/heartbeat', ['hardware' => $this->hardwareA])->assertStatus(409)->assertJsonPath('code', 'REPLAY_DETECTED');
    }
}
