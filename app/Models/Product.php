<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasUuids,SoftDeletes;

    protected $guarded = [];

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function releases()
    {
        return $this->hasMany(Release::class);
    }
}
