<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Concerns\HasUuids;
class Product extends Model { use HasUuids; protected $guarded=[]; public function uniqueIds(){return ['uuid'];} public function releases(){return $this->hasMany(Release::class);} }
