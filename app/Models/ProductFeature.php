<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductFeature extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return ['active' => 'boolean', 'is_required' => 'boolean', 'sort_order' => 'integer'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function licenses()
    {
        return $this->belongsToMany(License::class)->withTrashed();
    }
}
