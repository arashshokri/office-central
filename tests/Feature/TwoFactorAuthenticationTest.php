<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_two_factor_requires_a_second_step_and_accepts_a_single_use_recovery_code(): void
    {
        $recoveryCode = 'ABCD1234-EFGH5678';
        $user = User::factory()->create([
            'email' => 'secure@example.com',
            'password' => 'A-secure-password-123',
            'role' => 'super_admin',
            'active' => true,
            'locale' => 'en',
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => [Hash::make($recoveryCode)],
            'two_factor_confirmed_at' => now(),
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'A-secure-password-123',
        ])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();

        $this->post(route('two-factor.verify'), ['code' => $recoveryCode])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('en', app()->getLocale());
        $this->assertSame([], $user->fresh()->two_factor_recovery_codes);
    }

    public function test_invalid_second_factor_does_not_authenticate(): void
    {
        $user = User::factory()->create([
            'email' => 'secure@example.com',
            'password' => 'A-secure-password-123',
            'role' => 'admin',
            'active' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'A-secure-password-123']);
        $this->post(route('two-factor.verify'), ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_viewer_cannot_manage_administrators(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'active' => true]);

        $this->actingAs($viewer)->get(route('users.index'))->assertForbidden();
        $this->actingAs($viewer)->post(route('users.store'), [
            'name' => 'Denied',
            'email' => 'denied@example.com',
            'password' => 'A-secure-password-123',
            'password_confirmation' => 'A-secure-password-123',
            'role' => 'admin',
        ])->assertForbidden();
    }

    public function test_the_last_active_super_admin_cannot_be_demoted(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'active' => true]);

        $this->actingAs($admin)->put(route('users.update', $admin), [
            'role' => 'admin',
            'active' => true,
        ])->assertStatus(422);

        $this->assertSame('super_admin', $admin->fresh()->role);
    }
}
