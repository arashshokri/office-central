<?php
namespace App\Services;
final class LicenseKeyService { public function generate():string { $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $raw=''; for($i=0;$i<16;$i++)$raw.=$alphabet[random_int(0,strlen($alphabet)-1)]; return 'OFF-'.implode('-',str_split($raw,4)); } public function hash(string $key):string{return hash('sha256',strtoupper(trim($key)));} public function prefix(string $key):string{return substr(strtoupper($key),0,8);} }
