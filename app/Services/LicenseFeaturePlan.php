<?php

namespace App\Services;

use App\Models\License;
use App\Models\Product;
use App\Models\ProductFeature;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LicenseFeaturePlan
{
    public function data(Request $request): array
    {
        return $request->validate([
            'display_name' => 'nullable|string|max:120',
            'edition' => 'nullable|string|max:80',
            'planned_feature_policy' => 'sometimes|required|in:all,selected',
            'features' => 'sometimes|array|max:200',
            'features.*' => 'required|integer|distinct',
        ]);
    }

    // Call inside the license transaction. Product lock serializes catalog edits
    // with plan changes so product/feature membership cannot race validation.
    public function sync(License $license, array $featureIds): void
    {
        Product::withTrashed()->whereKey($license->product_id)->lockForUpdate()->firstOrFail();
        $features = ProductFeature::whereIn('id', $featureIds)->lockForUpdate()->get();
        $previous = $license->features()->pluck('product_features.id')->all();
        if ($features->count() !== count($featureIds) || $features->contains(fn ($feature) => $feature->product_id !== $license->product_id || (! $feature->active && ! in_array($feature->id, $previous, true)))) {
            throw ValidationException::withMessages(['features' => __('ui.invalid_feature_selection')]);
        }
        $license->features()->sync($license->planned_feature_policy === 'selected' ? $featureIds : []);
    }
}
