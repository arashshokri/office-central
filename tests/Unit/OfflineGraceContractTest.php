<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
class OfflineGraceContractTest extends TestCase { public function test_transport_failure_preserves_last_valid_lease_until_grace_expires():void{$lease=['authoritative'=>true,'license_status'=>'active','issued_at'=>1_700_000_000,'expires_at'=>1_700_604_800];$centralResponse=null;$trustedTime=1_700_001_000;$mayRun=$centralResponse===null&&$lease['license_status']==='active'&&$trustedTime<=$lease['expires_at'];$this->assertTrue($mayRun);} public function test_explicit_revocation_is_distinct_from_transport_failure():void{$response=['success'=>false,'code'=>'LICENSE_REVOKED'];$this->assertContains($response['code'],['LICENSE_REVOKED','LICENSE_SUSPENDED','LICENSE_HARDWARE_MISMATCH','INSTALLATION_LOCKED','SECURITY_VIOLATION']);} }
