<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Concerns\HasUuids;
class LicenseLease extends Model { use HasUuids; protected $guarded=[]; public function uniqueIds(){return ['uuid'];} protected function casts():array{return ['payload'=>'array','issued_at'=>'datetime','expires_at'=>'datetime'];} }
