<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\SoftDeletes; use Illuminate\Database\Eloquent\Concerns\HasUuids;
class Customer extends Model { use SoftDeletes,HasUuids; protected $guarded=[]; public function uniqueIds(){return ['uuid'];} public function licenses(){return $this->hasMany(License::class);} public function installations(){return $this->hasMany(Installation::class);} }
