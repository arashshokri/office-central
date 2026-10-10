<?php

namespace Tests\Feature;

use App\Http\Controllers\SettingsController;
use App\Models\User;
use App\Services\BackupManager;
use App\Services\OfficeLicense;
use Illuminate\Http\Request;
use Tests\TestCase;

class OfficeAgentLicenseTest extends TestCase
{
    private string $dir;

    private $process;

    private array $state;

    private string $centralSecret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/office-license-'.bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
        $pair = sodium_crypto_sign_keypair();
        $this->centralSecret = sodium_crypto_sign_secretkey($pair);
        $device = sodium_crypto_sign_keypair();
        $key = sodium_crypto_sign_secretkey($device);
        $hardware = hash('sha256', 'fixture-host');
        file_put_contents($this->dir.'/device.key', $key);
        $this->state = ['kind' => 'state', 'protocol' => 2, 'product' => 'office', 'installation_id' => 'test-installation',
            'sequence' => 1, 'access' => 'allowed', 'completed' => true, 'hardware_fingerprint' => $hardware, 'presented_fingerprint' => $hardware,
            'device_public_key' => sodium_bin2base64(sodium_crypto_sign_publickey($device), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)];
        file_put_contents($this->dir.'/fingerprint', $hardware);
        file_put_contents($this->dir.'/trust.json', json_encode(['installation_id' => 'test-installation',
            'public_key' => sodium_bin2base64(sodium_crypto_sign_publickey($pair), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)]));
        config(['office-agent.enabled' => true, 'office-agent.state_dir' => $this->dir,
            'office-agent.socket' => $this->dir.'/control.sock', 'office-agent.control_token' => str_repeat('a', 64)]);
        $this->writeState();
        $script = <<<'PHP'
<?php
$dir=$argv[1];
$server=stream_socket_server('unix://'.$dir.'/control.sock',$errno,$error);
while($connection=stream_socket_accept($server,10)){
    $header='';
    while(($line=fgets($connection))!==false){$header.=$line;if($line==="\r\n")break;}
    preg_match('/Content-Length: (\d+)/i',$header,$length);
    $body='';while(strlen($body)<(int)$length[1]){$body.=fread($connection,(int)$length[1]-strlen($body));}
    $data=json_decode($body,true);
    preg_match('~^POST (\S+)~',$header,$path);
    file_put_contents($dir.'/requests',json_encode(['path'=>$path[1],'body'=>$data])."\n",FILE_APPEND);
    if($path[1]==='/identity') {
        $fp=file_get_contents($dir.'/fingerprint');
        $sig=sodium_crypto_sign_detached("office-hardware-proof/v2\n".$data['Nonce']."\n".$fp,file_get_contents($dir.'/device.key'));
        $result=['fingerprint'=>$fp,'signature'=>sodium_bin2base64($sig,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)];
    } elseif($path[1]==='/check-update') {
        if(file_exists($dir.'/limited')) {
            $out=json_encode(['message'=>'Too Many Attempts.','retry_after'=>7]);
            fwrite($connection,"HTTP/1.1 429 Too Many Requests\r\nContent-Length: ".strlen($out)."\r\nRetry-After: 7\r\nConnection: close\r\n\r\n".$out);
            fclose($connection);continue;
        }
        $result=['confirmation_supported'=>!file_exists($dir.'/old-helper'),'installed_version'=>'3.8.22',
            'update'=>['available'=>true,'version'=>'3.8.23','release_id'=>'b80a7eb6-8d49-4f42-b3ed-16e3a916b57e']];
    } elseif($path[1]==='/update') {
        if(file_exists($dir.'/failed-start')) {
            $out=json_encode(['message'=>'BUILD_PREFLIGHT fixture failure']);
            fwrite($connection,"HTTP/1.1 502 Bad Gateway\r\nContent-Length: ".strlen($out)."\r\nConnection: close\r\n\r\n".$out);
            fclose($connection);continue;
        }
        $result=['id'=>file_exists($dir.'/duplicate-start')?'shared-job-fixture':bin2hex(random_bytes(8)),
            'status'=>'running','version'=>'3.8.23','stage'=>'queued'];
        if(!file_exists($dir.'/duplicate-start')) file_put_contents($dir.'/update.json',json_encode($result));
    } else {$result=['status'=>'running','version'=>'3.8.23'];}
    $out=json_encode($result);
    fwrite($connection,"HTTP/1.1 200 OK\r\nContent-Length: ".strlen($out)."\r\nConnection: close\r\n\r\n".$out);
    fclose($connection);
}
PHP;
        file_put_contents($this->dir.'/server.php', $script);
        $this->process = proc_open([PHP_BINARY, '-n', $this->dir.'/server.php', $this->dir], [0 => ['pipe', 'r'], 1 => ['file', $this->dir.'/stdout', 'a'], 2 => ['file', $this->dir.'/stderr', 'a']], $pipes);
        if (isset($pipes[0])) {
            fclose($pipes[0]);
        }
        for ($i = 0; $i < 100 && ! file_exists($this->dir.'/control.sock'); $i++) {
            usleep(10000);
        }
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        foreach (glob($this->dir.'/*') as $file) {
            unlink($file);
        } rmdir($this->dir);
        parent::tearDown();
    }

    private function writeState(): void
    {
        $raw = json_encode($this->state);
        file_put_contents($this->dir.'/state.json', json_encode(['payload' => sodium_bin2base64($raw, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
            'signature' => sodium_bin2base64(sodium_crypto_sign_detached("office-agent/v2\n".$raw, $this->centralSecret), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), 'algorithm' => 'Ed25519']));
    }

    public function test_signed_allowed_state_works_without_contacting_central(): void
    {
        $this->assertTrue(app(OfficeLicense::class)->decision()['allowed']);
        $this->assertTrue(app(OfficeLicense::class)->decision()['allowed']);
    }

    public function test_copied_state_is_locked_by_fresh_host_proof(): void
    {
        file_put_contents($this->dir.'/fingerprint', hash('sha256', 'cloned-host'));
        $this->assertFalse(app(OfficeLicense::class)->decision()['allowed']);
    }

    public function test_forged_state_and_authoritative_lock_are_rejected(): void
    {
        file_put_contents($this->dir.'/state.json', '{"payload":"ZmFrZQ","signature":"fake","algorithm":"Ed25519"}');
        $this->assertFalse(app(OfficeLicense::class)->decision()['allowed']);
        $this->state['access'] = 'locked';
        $this->writeState();
        $this->assertFalse(app(OfficeLicense::class)->decision()['allowed']);
    }

    public function test_legacy_owner_installation_remains_accessible(): void
    {
        config(['office-agent.enabled' => false]);
        $this->assertTrue(app(OfficeLicense::class)->decision()['allowed']);
    }

    public function test_connection_marker_enables_licensing_without_rebuilding_cached_configuration(): void
    {
        config(['office-agent.enabled' => false]);
        $this->state['access'] = 'locked';
        $this->writeState();
        $this->assertTrue(app(OfficeLicense::class)->decision()['allowed']);
        file_put_contents($this->dir.'/enabled', 'office-helper/v2');
        $this->assertFalse(app(OfficeLicense::class)->decision()['allowed']);
    }

    public function test_control_identity_can_be_added_after_office_configuration_was_cached(): void
    {
        config(['office-agent.control_token' => '', 'office-agent.control_token_file' => $this->dir.'/token']);
        file_put_contents($this->dir.'/token', str_repeat('a', 64));
        $this->assertTrue(app(OfficeLicense::class)->decision()['allowed']);
        unlink($this->dir.'/token');
        $this->assertFalse(app(OfficeLicense::class)->decision()['allowed']);
    }

    public function test_business_api_is_locked_but_login_remains_accessible(): void
    {
        $this->state['access'] = 'locked';
        $this->writeState();
        $this->getJson('/api/v1/projects')->assertStatus(423)->assertJsonPath('code', 'OFFICE_LICENSE_LOCKED');
        $this->get('/login')->assertOk();
    }

    public function test_system_update_page_uses_office_layout_and_readonly_signed_lifetime_license(): void
    {
        $this->state['license'] = ['id' => 'license-fixture', 'display_key' => 'OFF-TEST-AAAA-BBBB-CCCC', 'activated_at' => '2026-10-09T08:00:00Z', 'expires_at' => null, 'customer' => 'مشتری آزمایشی'];
        $this->writeState();
        $user = User::factory()->make(['id' => 1, 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($user)->get('/settings/system-update')->assertOk()->assertSee('بروزرسانی سامانه')->assertSee('لایسنس مادام العمر')->assertDontSee('license-fixture')->assertSee('OFF-TEST-AAAA-BBBB-CCCC')->assertDontSee('فقط خواندنی')->assertDontSee('متصل به مرکز')->assertSee('readonly', false)->assertSee('app-theme')->assertSee('officeUpdateConfirm')->assertSee('checkProgressTrack')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_update_routes_are_restricted_to_administrator(): void
    {
        foreach (['employee', 'manager', 'supervisor'] as $id => $role) {
            $user = User::factory()->make(['id' => $id + 10, 'role' => $role, 'is_active' => true]);
            $this->actingAs($user)->get('/settings/system-update')->assertForbidden();
            $this->postJson('/settings/system-update/check')->assertForbidden();
            $this->postJson('/settings/system-update/install')->assertForbidden();
            $this->getJson('/settings/system-update/status')->assertForbidden();
            $this->post('/license/reactivate', ['license_key' => 'OFF-TEST-AAAA-BBBB-CCCC'])->assertForbidden();
        }
    }

    public function test_general_manager_can_check_confirm_update_and_reactivate(): void
    {
        $user = User::factory()->make(['id' => 2, 'role' => 'general_manager', 'is_active' => true]);
        $this->actingAs($user)->get('/settings/system-update')->assertOk();
        $this->postJson('/settings/system-update/check')->assertOk()->assertJsonPath('update.version', '3.8.23');
        $this->postJson('/settings/system-update/install')->assertUnprocessable();
        $confirmation = ['expected_version' => '3.8.23', 'expected_release_id' => 'b80a7eb6-8d49-4f42-b3ed-16e3a916b57e'];
        $this->postJson('/settings/system-update/install', $confirmation)->assertStatus(202)->assertJsonPath('status', 'running');
        $requests = array_map(fn ($line) => json_decode($line, true), file($this->dir.'/requests', FILE_IGNORE_NEW_LINES));
        $updates = array_values(array_filter($requests, fn ($request) => $request['path'] === '/update'));
        $this->assertSame($confirmation, $updates[0]['body']);
        $this->getJson('/settings/system-update/status')->assertOk();
        $this->state['access'] = 'locked';
        $this->writeState();
        $this->get('/license')->assertOk()->assertSee('واحد فروش یا نماینده فنی خود')->assertSee('license_key')->assertSee('app-theme');
        $this->post('/license/reactivate', ['license_key' => 'OFF-TEST-AAAA-BBBB-CCCC'])->assertRedirect('/');
    }

    public function test_old_helper_cannot_start_unpinned_update(): void
    {
        file_put_contents($this->dir.'/old-helper', '1');
        $user = User::factory()->make(['id' => 3, 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($user)->postJson('/settings/system-update/install', ['expected_version' => '3.8.23'])->assertStatus(502)->assertSee('HELPER_UPGRADE_REQUIRED');
        $this->assertStringNotContainsString('"path":"\/update"', file_get_contents($this->dir.'/requests'));
    }

    public function test_status_polling_does_not_spend_check_install_or_activation_limits(): void
    {
        $user = User::factory()->make(['id' => 81, 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($user);
        for ($i = 0; $i < 8; $i++) {
            $this->getJson('/settings/system-update/status')->assertOk();
        }
        $this->postJson('/settings/system-update/check')->assertOk()->assertJsonPath('update.version', '3.8.23');
        $this->postJson('/settings/system-update/install', ['expected_version' => '3.8.23'])->assertStatus(202);
        $this->post('/license/reactivate', ['license_key' => 'OFF-TEST-AAAA-BBBB-CCCC'])->assertRedirect('/');
    }

    public function test_check_limit_is_enforced_per_user_without_blocking_status_or_installation(): void
    {
        $user = User::factory()->make(['id' => 82, 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($user);
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/settings/system-update/check')->assertOk();
        }
        $response = $this->postJson('/settings/system-update/check')->assertStatus(429)->assertHeader('Retry-After');
        $this->assertGreaterThan(0, $response->json('retry_after'));
        $this->assertStringNotContainsString('Too Many Attempts', $response->json('message'));
        $this->getJson('/settings/system-update/status')->assertOk();
        $this->postJson('/settings/system-update/install', ['expected_version' => '3.8.23'])->assertStatus(202);
        $other = User::factory()->make(['id' => 83, 'role' => 'general_manager', 'is_active' => true]);
        $this->actingAs($other)->postJson('/settings/system-update/check')->assertOk();
        $this->travel(61)->seconds();
        $this->actingAs($user)->postJson('/settings/system-update/check')->assertOk();
    }

    public function test_central_throttle_retains_status_and_retry_deadline_in_office(): void
    {
        file_put_contents($this->dir.'/limited', '1');
        $user = User::factory()->make(['id' => 84, 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($user)->postJson('/settings/system-update/check')->assertStatus(429)
            ->assertHeader('Retry-After', '7')->assertJsonPath('retry_after', 7)
            ->assertDontSee('Too Many Attempts');
        $this->getJson('/settings/system-update/status')->assertOk();
    }

    public function test_failed_and_invalid_starts_do_not_spend_the_new_operation_quota(): void
    {
        $this->actingAs(User::factory()->make(['id' => 85, 'role' => 'admin', 'is_active' => true]));
        file_put_contents($this->dir.'/failed-start', '1');
        for ($i = 0; $i < 12; $i++) {
            $this->postJson('/settings/system-update/install', ['expected_version' => '3.8.23'])
                ->assertStatus(502)->assertSee('BUILD_PREFLIGHT');
        }
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/settings/system-update/install')->assertUnprocessable();
        }
        unlink($this->dir.'/failed-start');
        $this->postJson('/settings/system-update/install', ['expected_version' => '3.8.23'])
            ->assertStatus(202)->assertJsonPath('status', 'running')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_running_operation_is_returned_without_rechecking_central_or_starting_again(): void
    {
        $this->actingAs(User::factory()->make(['id' => 86, 'role' => 'admin', 'is_active' => true]));
        $confirmation = ['expected_version' => '3.8.23'];
        $id = $this->postJson('/settings/system-update/install', $confirmation)->assertStatus(202)->json('id');
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/settings/system-update/install', $confirmation)->assertStatus(202)->assertJsonPath('id', $id);
        }
        $requests = array_map(fn ($line) => json_decode($line, true), file($this->dir.'/requests', FILE_IGNORE_NEW_LINES));
        $this->assertCount(1, array_filter($requests, fn ($request) => $request['path'] === '/update'));
        $this->assertCount(1, array_filter($requests, fn ($request) => $request['path'] === '/check-update'));
    }

    public function test_new_operation_limit_remains_bounded_and_never_hides_a_running_job(): void
    {
        $this->actingAs(User::factory()->make(['id' => 87, 'role' => 'admin', 'is_active' => true]));
        $confirmation = ['expected_version' => '3.8.23'];
        for ($i = 0; $i < 10; $i++) {
            file_put_contents($this->dir.'/update.json', json_encode(['status' => 'error', 'stage' => 'build']));
            $id = $this->postJson('/settings/system-update/install', $confirmation)->assertStatus(202)->json('id');
        }
        $this->postJson('/settings/system-update/install', $confirmation)->assertStatus(202)->assertJsonPath('id', $id);
        file_put_contents($this->dir.'/update.json', json_encode(['status' => 'error', 'stage' => 'build']));
        $this->postJson('/settings/system-update/install', $confirmation)->assertStatus(429)->assertHeader('Retry-After');
        $this->getJson('/settings/system-update/status')->assertOk();
        $this->postJson('/settings/system-update/check')->assertOk();
        $this->travel(61)->seconds();
        $this->postJson('/settings/system-update/install', $confirmation)->assertStatus(202);
    }

    public function test_invalid_install_request_flood_is_still_bounded_independently(): void
    {
        $this->actingAs(User::factory()->make(['id' => 88, 'role' => 'admin', 'is_active' => true]));
        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/settings/system-update/install')->assertUnprocessable();
        }
        $this->postJson('/settings/system-update/install')->assertStatus(429)->assertHeader('Retry-After');
        $this->getJson('/settings/system-update/status')->assertOk();
        $this->postJson('/settings/system-update/check')->assertOk();
    }

    public function test_duplicate_accepted_response_is_counted_once_even_before_status_file_is_visible(): void
    {
        file_put_contents($this->dir.'/duplicate-start', '1');
        $this->actingAs(User::factory()->make(['id' => 89, 'role' => 'admin', 'is_active' => true]));
        for ($i = 0; $i < 15; $i++) {
            $this->postJson('/settings/system-update/install', ['expected_version' => '3.8.23'])
                ->assertStatus(202)->assertJsonPath('id', 'shared-job-fixture');
        }
    }

    public function test_legacy_shared_counter_is_reproduced_but_does_not_block_new_update_routes(): void
    {
        \Illuminate\Support\Facades\Route::get('/_legacy-update/status', fn () => response()->json([]))
            ->middleware(['web', 'auth', 'throttle:60,1']);
        \Illuminate\Support\Facades\Route::post('/_legacy-update/install', fn () => response()->json([]))
            ->middleware(['web', 'auth', 'throttle:3,1']);
        $this->actingAs(User::factory()->make(['id' => 90, 'role' => 'admin', 'is_active' => true]));
        for ($i = 0; $i < 4; $i++) {
            $this->getJson('/_legacy-update/status')->assertOk();
        }
        $this->postJson('/_legacy-update/install')->assertStatus(429)->assertSee('Too Many Attempts');
        $this->postJson('/settings/system-update/check')->assertOk();
        $this->postJson('/settings/system-update/install', ['expected_version' => '3.8.23'])->assertStatus(202);
    }

    public function test_settings_tile_is_visible_only_to_the_two_administrator_roles(): void
    {
        $backup = \Mockery::mock(BackupManager::class);
        $backup->shouldReceive('dashboardStatus')->andReturn([]);
        foreach (['admin', 'general_manager', 'supervisor'] as $role) {
            $user = User::factory()->make(['id' => 50, 'role' => $role, 'is_active' => true]);
            $request = Request::create('/settings');
            $request->setUserResolver(fn () => $user);
            $view = app(SettingsController::class)($request, $backup);
            $item = $view->getData()['settingsItems']->firstWhere('title', 'بروزرسانی سامانه');
            if ($role === 'supervisor') {
                $this->assertNull($item);
            } else {
                $this->assertNotNull($item);
                $this->assertStringNotContainsString('مرکز', $item['description']);
            }
        }
    }

    public function test_guest_lock_uses_office_assets_and_does_not_expose_activation_form(): void
    {
        $this->state['access'] = 'locked';
        $this->writeState();
        $this->get('/projects')->assertStatus(423)->assertSee('واحد فروش یا نماینده فنی خود')->assertSee('vendor/vazirmatn/vazirmatn.css')->assertSee('app-theme')->assertDontSee('name="license_key"', false)->assertSee('اطلاعات، فایل‌ها و دیتابیس شما حفظ شده‌اند.');
    }

    public function test_maintenance_blocks_business_but_preserves_login_and_admin_status_endpoint(): void
    {
        file_put_contents($this->dir.'/update.json', json_encode(['status' => 'error', 'stage' => 'migration', 'maintenance' => true, 'error' => 'SQLSTATE fixture']));
        $this->getJson('/api/v1/projects')->assertStatus(503)->assertJsonPath('code', 'OFFICE_UPDATE_MAINTENANCE');
        $this->get('/login')->assertOk();
        $user = User::factory()->make(['id' => 1, 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($user)->getJson('/settings/system-update/status')->assertOk()->assertJsonPath('error', 'SQLSTATE fixture');
    }

    public function test_update_progress_is_inside_the_confirmation_window(): void
    {
        $user = User::factory()->make(['id' => 1, 'role' => 'admin', 'is_active' => true]);
        $html = $this->actingAs($user)->get('/settings/system-update')->assertOk()->getContent();
        $modal = strpos($html, 'id="officeUpdateConfirm"');
        $progress = strpos($html, 'id="installProgressTrack"');
        $this->assertNotFalse($modal);
        $this->assertNotFalse($progress);
        $this->assertGreaterThan($modal, $progress);
        $this->assertStringContainsString('بستن پنجره، عملیات را متوقف نمی‌کند.', $html);
        $this->assertStringContainsString('id="showUpdateProgress"', $html);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame('button', $xpath->query('//*[@id="showUpdateProgress"]')->item(0)->tagName);
        $this->assertSame(0, $xpath->query('//*[@id="operationSummary"]/ancestor::button')->length);
        $this->assertSame(1, $xpath->query('//*[@id="installPercent"]/ancestor::*[@id="officeUpdateConfirm"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="installProgressTrack"]/ancestor::*[@id="officeUpdateConfirm"]')->length);
    }
}
