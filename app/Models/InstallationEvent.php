<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class InstallationEvent extends Model { protected $guarded=[]; protected function casts():array{return ['context'=>'array','occurred_at'=>'datetime'];} }
