<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Concerns\HasUuids;
class AuditLog extends Model { use HasUuids; protected $guarded=[]; public function uniqueIds(){return ['uuid'];} protected function casts():array{return ['before'=>'array','after'=>'array','occurred_at'=>'datetime'];} }
