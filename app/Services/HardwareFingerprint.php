<?php
namespace App\Services;
final class HardwareFingerprint { private const KEYS=['machine_id','product_uuid','system_serial','board_serial','primary_disk_serial']; public function make(array $hardware,int $version=1):string { if($version===2){
    $uuid=strtolower(trim((string)($hardware['product_uuid']??'')));
    $machine=strtolower(trim((string)($hardware['machine_id']??'')));
    if(!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$uuid)
        || !preg_match('/^[a-f0-9]{32}$/D',$machine)
        || in_array(str_replace('-','',$uuid),[str_repeat('0',32),str_repeat('f',32)],true)
        || $machine===str_repeat('0',32)) throw new \InvalidArgumentException('A valid host DMI product UUID and machine-id are required.');
    return hash('sha256',"office-hardware-v2\n".$uuid."\n".$machine);
} $parts=[]; foreach(self::KEYS as $key){$value=strtolower(trim((string)($hardware[$key]??''))); if($value!=='')$parts[$key]=$value;} ksort($parts); if(count($parts)<2) throw new \InvalidArgumentException('At least two stable hardware identifiers are required.'); return hash('sha256',$version.'|'.json_encode($parts,JSON_UNESCAPED_SLASHES)); } public function componentHashes(array $hardware):array { $map=['machine_id'=>'machine_id_hash','product_uuid'=>'product_uuid_hash','board_serial'=>'board_serial_hash','system_serial'=>'system_serial_hash','primary_disk_serial'=>'disk_serial_hash']; $out=[]; foreach($map as $input=>$column){$out[$column]=isset($hardware[$input])?hash('sha256',strtolower(trim((string)$hardware[$input]))):null;} return $out; } }
