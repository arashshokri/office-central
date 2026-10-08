<?php
namespace Tests\Feature;

use App\Models\{Customer, Product, Release, License, Installation};
use App\Services\{LicenseKeyService, SigningService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstallerProtocolTest extends TestCase
{
    use RefreshDatabase;
    private string $secret;
    private string $public;
    private string $requestId;
    private array $hardware = ['product_uuid'=>'20b612fc-d40b-43aa-a660-00ff0202dd10','machine_id'=>'00112233445566778899aabbccddeeff'];
    protected function setUp(): void {
        parent::setUp();
        $pair=SigningService::generate();
        config(['office.require_https'=>false,'office.signing_private_key'=>$pair['private'],'office.signing_public_key'=>$pair['public']]);
        $this->secret=sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->public=sodium_bin2base64(sodium_crypto_sign_publickey_from_secretkey($this->secret),SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $this->requestId=(string)Str::uuid();
    }
    private function license(string $key='OFF-AAAA-BBBB-CCCC-DDDD',?License $original=null): License {
        $customer=$original?->customer ?? Customer::create(['name'=>'Test Customer','status'=>'active']);
        $product=$original?->product ?? Product::create(['name'=>'Office','slug'=>'office','status'=>'active']);
        $release=$original?->release ?? Release::create(['product_id'=>$product->id,'version'=>'3.8.19','channel'=>'stable','status'=>'published','source_type'=>'manual','package_path'=>'test.zip','package_size'=>100,'package_sha256'=>str_repeat('a',64),'runtime_manifest'=>['format'=>'office-runtime-v1'],'published_at'=>now()]);
        return License::create(['customer_id'=>$customer->id,'product_id'=>$product->id,'release_id'=>$release->id,'license_key_hash'=>app(LicenseKeyService::class)->hash($key),'license_key_prefix'=>'OFF-AAAA','status'=>'created','max_installations'=>1,'activation_mode'=>'installer_once','deployment_config'=>['app_url'=>'https://office.customer.ir','admin_email'=>'admin@customer.ir']]);
    }
    private function activation(string $key='OFF-AAAA-BBBB-CCCC-DDDD'):array {
        return ['license_key'=>$key,'hostname'=>'test-office','client_request_id'=>$this->requestId,'hardware'=>$this->hardware,'device_public_key'=>$this->public,'agent_version'=>'1.4.0'];
    }
    private function signed(string $action,array $data,string $credential='',?string $nonce=null) {
        $path='/api/v2/installer/'.$action;$raw=json_encode($data);$timestamp=(string)time();$nonce??=bin2hex(random_bytes(16));
        $signature=sodium_crypto_sign_detached("POST\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256',$raw),$this->secret);
        return $this->call('POST',$path,[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json',
            'HTTP_AUTHORIZATION'=>'Bearer '.$credential,'HTTP_X_REQUEST_NONCE'=>$nonce,'HTTP_X_REQUEST_TIMESTAMP'=>$timestamp,
            'HTTP_X_DEVICE_SIGNATURE'=>sodium_bin2base64($signature,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)],$raw);
    }
    private function payload(array $envelope):array {
        $raw=sodium_base642bin($envelope['payload'],SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $this->assertTrue(sodium_crypto_sign_verify_detached(sodium_base642bin($envelope['signature'],SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),"office-agent/v2\n".$raw,sodium_base642bin(config('office.signing_public_key'),SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)));
        return json_decode($raw,true);
    }
    private function receipt(Release $release):array {
        return ['hardware'=>$this->hardware,'release_id'=>$release->uuid,'package_sha256'=>$release->package_sha256,'application_version'=>$release->version,'health_ok'=>true];
    }
    public function test_begin_reserves_resume_is_idempotent_and_only_completion_consumes():void {
        $license=$this->license();
        $first=$this->signed('begin',$this->activation())->assertOk()->json('data');
        $this->assertNull($license->fresh()->consumed_at);
        $this->assertSame('provisioning',$this->payload($first['signed_state'])['access']);
        $resumed=$this->signed('begin',$this->activation())->assertOk()->json('data');
        $this->assertSame($first['credential'],$resumed['credential']);
        $this->assertDatabaseCount('installations',1);
        $bad=$this->receipt($license->release);$bad['package_sha256']=str_repeat('b',64);
        $this->signed('complete',$bad,$first['credential'])->assertStatus(422);
        $this->assertNull($license->fresh()->consumed_at);
        $complete=$this->signed('complete',$this->receipt($license->release),$first['credential'])->assertOk()->json('data.signed_state');
        $this->assertSame('allowed',$this->payload($complete)['access']);
        $this->assertNotNull($license->fresh()->consumed_at);
        $this->signed('complete',$this->receipt($license->release),$first['credential'])->assertOk();
        $this->requestId=(string)Str::uuid();
        $this->signed('begin',$this->activation())->assertStatus(409)->assertJsonPath('code','LICENSE_CONSUMED');
    }
    public function test_clone_lock_is_signed_and_original_remains_allowed():void {
        $license=$this->license();$data=$this->signed('begin',$this->activation())->json('data');
        $this->signed('complete',$this->receipt($license->release),$data['credential'])->assertOk();
        $clone=$this->hardware;$clone['product_uuid']='30b612fc-d40b-43aa-a660-00ff0202dd10';
        $signed=$this->signed('state',['hardware'=>$clone],$data['credential'])->assertOk()->json('data.signed_state');
        $this->assertSame('locked',$this->payload($signed)['access']);
        $this->assertSame('active',Installation::first()->status->value);
        $signed=$this->signed('state',['hardware'=>$this->hardware],$data['credential'])->assertOk()->json('data.signed_state');
        $this->assertSame('allowed',$this->payload($signed)['access']);
        $this->assertDatabaseHas('security_events',['type'=>'clone_detected']);
        $this->signed('download',['hardware'=>$clone,'release_id'=>$license->release->uuid],$data['credential'])->assertForbidden();
    }
    public function test_signature_nonce_and_legacy_bypass_are_rejected():void {
        $this->license();$data=$this->signed('begin',$this->activation())->assertOk()->json('data');
        $nonce=bin2hex(random_bytes(16));
        $this->signed('state',['hardware'=>$this->hardware],$data['credential'],$nonce)->assertOk();
        $this->signed('state',['hardware'=>$this->hardware],$data['credential'],$nonce)->assertStatus(409);
        $this->postJson('/api/v2/installer/begin',$this->activation())->assertUnauthorized();
        $this->postJson('/api/v1/agent/activate',['license_key'=>'OFF-AAAA-BBBB-CCCC-DDDD','hostname'=>'legacy','hardware'=>$this->hardware])->assertStatus(409);
    }
    public function test_reactivation_consumes_new_code_without_touching_original():void {
        $license=$this->license();$data=$this->signed('begin',$this->activation())->json('data');
        $this->signed('complete',$this->receipt($license->release),$data['credential'])->assertOk();
        $replacement=$this->license('OFF-EEEE-FFFF-GGGG-HHHH',$license);
        $this->hardware['product_uuid']='40b612fc-d40b-43aa-a660-00ff0202dd10';
        $this->requestId=(string)Str::uuid();$body=$this->activation('OFF-EEEE-FFFF-GGGG-HHHH');$body['health_ok']=true;
        $new=$this->signed('reactivate',$body,$data['credential'])->assertOk()->json('data');
        $this->assertNotSame($data['installation_id'],$new['installation_id']);
        $this->assertSame('allowed',$this->payload($new['signed_state'])['access']);
        $this->assertNotNull($replacement->fresh()->consumed_at);
        $this->assertSame('active',$license->fresh()->status->value);
        $this->assertDatabaseCount('installations',2);
        $retry=$this->signed('reactivate',$body,$data['credential'])->assertOk()->json('data');
        $this->assertSame($new['credential'],$retry['credential']);
    }
    public function test_uuid_validation_and_assigned_release_download():void {
        $license=$this->license();$body=$this->activation();$body['hardware']['product_uuid']=str_repeat('0',32);
        $this->signed('begin',$body)->assertStatus(422);
        $data=$this->signed('begin',$this->activation())->assertOk()->json('data');
        $this->signed('download',['hardware'=>$this->hardware,'release_id'=>(string)Str::uuid()],$data['credential'])->assertForbidden();
        $envelope=$this->signed('download',['hardware'=>$this->hardware,'release_id'=>$license->release->uuid],$data['credential'])->assertOk()->json('data.signed_download');
        $this->assertStringStartsWith('https://update.ponet.ir/',$this->payload($envelope)['url']);
    }
    public function test_reactivation_rejects_another_customer_and_pending_code_is_reserved():void {
        $license=$this->license();$data=$this->signed('begin',$this->activation())->assertOk()->json('data');
        $this->requestId=(string)Str::uuid();
        $this->signed('begin',$this->activation())->assertStatus(409)->assertJsonPath('code','LICENSE_RESERVED');
        $this->signed('complete',$this->receipt($license->release),$data['credential'])->assertOk();
        $new=$this->license('OFF-EEEE-FFFF-GGGG-HHHH',$license);
        $customer=Customer::create(['name'=>'Other customer','status'=>'active']);$new->update(['customer_id'=>$customer->id]);
        $body=$this->activation('OFF-EEEE-FFFF-GGGG-HHHH');$body['health_ok']=true;
        $this->signed('reactivate',$body,$data['credential'])->assertForbidden()->assertJsonPath('code','LICENSE_PRODUCT_MISMATCH');
        $this->assertNull($new->fresh()->consumed_at);
    }

    public function test_existing_office_connects_without_package_and_consumes_only_after_healthy_completion(): void
    {
        $license = $this->license();
        $license->update(['activation_mode' => 'attach_once', 'release_id' => null]);
        $body = $this->activation(); $body['intent'] = 'connect';
        $first = $this->signed('begin', $body)->assertOk()->json('data');
        $this->assertNull($license->fresh()->consumed_at);
        $this->assertNull($this->payload($first['signed_state'])['package']);
        $receipt = ['hardware' => $this->hardware, 'application_version' => '3.8.20-rc.2', 'health_ok' => false];
        $this->signed('complete', $receipt, $first['credential'])->assertUnprocessable();
        $this->assertNull($license->fresh()->consumed_at);
        $receipt['health_ok'] = true;
        $result = $this->signed('complete', $receipt, $first['credential'])->assertOk()->json('data.signed_state');
        $this->assertSame('allowed', $this->payload($result)['access']);
        $this->assertNotNull($license->fresh()->consumed_at);
        $clone = $this->hardware; $clone['product_uuid'] = '30b612fc-d40b-43aa-a660-00ff0202dd10';
        $result = $this->signed('state', ['hardware' => $clone], $first['credential'])->assertOk()->json('data.signed_state');
        $this->assertSame('locked', $this->payload($result)['access']);
        $this->assertSame('active', Installation::first()->status->value);
    }

    public function test_connection_code_cannot_be_used_for_a_fresh_installation(): void
    {
        $license = $this->license(); $license->update(['activation_mode' => 'attach_once', 'release_id' => null]);
        $this->signed('begin', $this->activation())->assertUnprocessable()->assertJsonPath('code', 'HELPER_MODE_MISMATCH');
        $this->assertDatabaseCount('installations', 0);
        $this->assertNull($license->fresh()->consumed_at);
    }

    public function test_attached_customer_gets_only_authorized_security_update_and_confirms_it_idempotently(): void
    {
        $license=$this->license();$license->update(['activation_mode'=>'attach_once','release_id'=>null,'expires_at'=>now()->addYear()]);
        $body=$this->activation();$body['intent']='connect';$data=$this->signed('begin',$body)->assertOk()->json('data');
        $this->signed('complete',['hardware'=>$this->hardware,'application_version'=>'3.8.20','health_ok'=>true],$data['credential'])->assertOk();
        $state=$this->payload($this->signed('state',['hardware'=>$this->hardware],$data['credential'])->assertOk()->json('data.signed_state'));
        $this->assertSame($license->uuid,$state['license']['id']);$this->assertNotNull($state['license']['activated_at']);
        $this->assertNotNull($state['license']['expires_at']);$this->assertFalse($state['update']['available']);
        $this->assertStringNotContainsString('OFF-AAAA-BBBB-CCCC-DDDD',json_encode($state));
        $release=Release::create(['product_id'=>$license->product_id,'version'=>'3.8.21','channel'=>'stable','is_security'=>true,'status'=>'published',
            'source_type'=>'manual','package_path'=>'security.zip','package_size'=>100,'package_sha256'=>str_repeat('b',64),'runtime_manifest'=>['format'=>'office-runtime-v1'],'release_notes'=>'Security fix']);
        $this->signed('download',['hardware'=>$this->hardware,'release_id'=>$release->uuid],$data['credential'])->assertForbidden();
        $license->update(['update_release_id'=>$release->id]);
        $state=$this->payload($this->signed('state',['hardware'=>$this->hardware],$data['credential'])->assertOk()->json('data.signed_state'));
        $this->assertTrue($state['update']['available']);$this->assertTrue($state['update']['security']);$this->assertSame('3.8.21',$state['update']['version']);
        $this->signed('download',['hardware'=>$this->hardware,'release_id'=>$release->uuid],$data['credential'])->assertOk();
        $bad=$this->receipt($release);$bad['package_sha256']=str_repeat('c',64);
        $this->signed('complete',$bad,$data['credential'])->assertUnprocessable();$this->assertSame('3.8.20',Installation::first()->application_version);
        $this->signed('complete',$this->receipt($release),$data['credential'])->assertOk();
        $this->signed('complete',$this->receipt($release),$data['credential'])->assertOk();
        $this->assertSame('3.8.21',Installation::first()->application_version);
        $state=$this->payload($this->signed('state',['hardware'=>$this->hardware],$data['credential'])->assertOk()->json('data.signed_state'));
        $this->assertFalse($state['update']['available']);$this->assertDatabaseCount('installations',1);
        $license->update(['update_release_id'=>null]);
        $next=Release::create(['product_id'=>$license->product_id,'version'=>'3.8.22','channel'=>'stable','status'=>'published','source_type'=>'manual',
            'package_path'=>'next.zip','package_size'=>100,'package_sha256'=>str_repeat('c',64),'runtime_manifest'=>['format'=>'office-runtime-v1']]);
        $this->signed('download',['hardware'=>$this->hardware,'release_id'=>$next->uuid],$data['credential'])->assertForbidden();
        $license->update(['update_release_id'=>$next->id]);
        $this->signed('complete',$this->receipt($release),$data['credential'])->assertOk();
        $this->assertSame('3.8.21',Installation::first()->application_version);
        $state=$this->payload($this->signed('state',['hardware'=>$this->hardware],$data['credential'])->assertOk()->json('data.signed_state'));
        $this->assertTrue($state['update']['available']);
        $this->assertSame('3.8.22',$state['update']['version']);
    }
}
