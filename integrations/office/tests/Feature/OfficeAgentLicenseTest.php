<?php
namespace Tests\Feature;

use App\Services\OfficeLicense;
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
        $pair = sodium_crypto_sign_keypair(); $this->centralSecret = sodium_crypto_sign_secretkey($pair);
        $device = sodium_crypto_sign_keypair(); $key = sodium_crypto_sign_secretkey($device);
        $hardware = hash('sha256', 'fixture-host');
        file_put_contents($this->dir.'/device.key', $key);
        $this->state = ['kind'=>'state','protocol'=>2,'product'=>'office','installation_id'=>'test-installation',
            'sequence'=>1,'access'=>'allowed','completed'=>true,'hardware_fingerprint'=>$hardware,'presented_fingerprint'=>$hardware,
            'device_public_key'=>sodium_bin2base64(sodium_crypto_sign_publickey($device),SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)];
        file_put_contents($this->dir.'/fingerprint', $hardware);
        file_put_contents($this->dir.'/trust.json', json_encode(['installation_id'=>'test-installation',
            'public_key'=>sodium_bin2base64(sodium_crypto_sign_publickey($pair),SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)]));
        config(['office-agent.enabled'=>true,'office-agent.state_dir'=>$this->dir,
            'office-agent.socket'=>$this->dir.'/control.sock','office-agent.control_token'=>str_repeat('a',64)]);
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
    $fp=file_get_contents($dir.'/fingerprint');
    $sig=sodium_crypto_sign_detached("office-hardware-proof/v2\n".$data['Nonce']."\n".$fp,file_get_contents($dir.'/device.key'));
    $out=json_encode(['fingerprint'=>$fp,'signature'=>sodium_bin2base64($sig,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)]);
    fwrite($connection,"HTTP/1.1 200 OK\r\nContent-Length: ".strlen($out)."\r\nConnection: close\r\n\r\n".$out);
    fclose($connection);
}
PHP;
        file_put_contents($this->dir.'/server.php', $script);
        $this->process = proc_open([PHP_BINARY, '-n', $this->dir.'/server.php', $this->dir], [0=>['pipe','r'],1=>['file',$this->dir.'/stdout','a'],2=>['file',$this->dir.'/stderr','a']], $pipes);
        if (isset($pipes[0])) { fclose($pipes[0]); }
        for ($i=0;$i<100&&!file_exists($this->dir.'/control.sock');$i++) { usleep(10000); }
    }
    protected function tearDown(): void
    {
        if (is_resource($this->process)) { proc_terminate($this->process); proc_close($this->process); }
        foreach (glob($this->dir.'/*') as $file) { unlink($file); } rmdir($this->dir);
        parent::tearDown();
    }
    private function writeState(): void {
        $raw=json_encode($this->state);
        file_put_contents($this->dir.'/state.json',json_encode(['payload'=>sodium_bin2base64($raw,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
            'signature'=>sodium_bin2base64(sodium_crypto_sign_detached("office-agent/v2\n".$raw,$this->centralSecret),SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),'algorithm'=>'Ed25519']));
    }
    public function test_signed_allowed_state_works_without_contacting_central(): void {
        $this->assertTrue(app(OfficeLicense::class)->decision()['allowed']);
        $this->assertTrue(app(OfficeLicense::class)->decision()['allowed']);
    }
    public function test_copied_state_is_locked_by_fresh_host_proof(): void {
        file_put_contents($this->dir.'/fingerprint',hash('sha256','cloned-host'));
        $this->assertFalse(app(OfficeLicense::class)->decision()['allowed']);
    }
    public function test_forged_state_and_authoritative_lock_are_rejected(): void {
        file_put_contents($this->dir.'/state.json','{"payload":"ZmFrZQ","signature":"fake","algorithm":"Ed25519"}');
        $this->assertFalse(app(OfficeLicense::class)->decision()['allowed']);
        $this->state['access']='locked';$this->writeState();
        $this->assertFalse(app(OfficeLicense::class)->decision()['allowed']);
    }
    public function test_legacy_owner_installation_remains_accessible(): void {
        config(['office-agent.enabled'=>false]);
        $this->assertTrue(app(OfficeLicense::class)->decision()['allowed']);
    }
    public function test_connection_marker_enables_licensing_without_rebuilding_cached_configuration(): void {
        config(['office-agent.enabled'=>false]);
        $this->state['access']='locked';$this->writeState();
        $this->assertTrue(app(OfficeLicense::class)->decision()['allowed']);
        file_put_contents($this->dir.'/enabled', 'office-helper/v2');
        $this->assertFalse(app(OfficeLicense::class)->decision()['allowed']);
    }
    public function test_control_identity_can_be_added_after_office_configuration_was_cached(): void {
        config(['office-agent.control_token'=>'', 'office-agent.control_token_file'=>$this->dir.'/token']);
        file_put_contents($this->dir.'/token', str_repeat('a',64));
        $this->assertTrue(app(OfficeLicense::class)->decision()['allowed']);
        unlink($this->dir.'/token');
        $this->assertFalse(app(OfficeLicense::class)->decision()['allowed']);
    }
    public function test_business_api_is_locked_but_login_remains_accessible(): void {
        $this->state['access']='locked';$this->writeState();
        $this->getJson('/api/v1/projects')->assertStatus(423)->assertJsonPath('code','OFFICE_LICENSE_LOCKED');
        $this->get('/login')->assertOk();
    }
}
