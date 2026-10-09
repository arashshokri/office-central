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

    public function features()
    {
        return $this->hasMany(ProductFeature::class)->orderBy('sort_order')->orderBy('name');
    }

    public function supportsOfficeHelper(): bool
    {
        // Recognize verified packages without renaming products and their licenses.
        return $this->slug === 'office' || $this->releases()->where(function ($query) {
            $query->where('source_manifest->format', 'office-source-v1')
                ->orWhere('runtime_manifest->format', 'office-runtime-v1');
        })->exists();
    }
}
