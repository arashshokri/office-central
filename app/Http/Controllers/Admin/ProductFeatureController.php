<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductFeature;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductFeatureController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:120', 'product_id' => 'nullable|integer',
            'status' => 'nullable|in:active,inactive', 'category' => 'nullable|string|max:120']);
        $rows = ProductFeature::with('product')->withCount('licenses')
            ->when($filters['q'] ?? null, fn ($q, $value) => $q->where(fn ($q) => $q->whereLike('name', '%'.$value.'%')->orWhereLike('key', '%'.$value.'%')))
            ->when($filters['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('active', $status === 'active'))
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->orderBy('sort_order')->orderBy('name')->paginate(20)->withQueryString();

        return view('admin.features', ['rows' => $rows, 'products' => Product::orderBy('name')->get(),
            'categories' => ProductFeature::whereNotNull('category')->distinct()->orderBy('category')->pluck('category')]);
    }

    public function create()
    {
        return $this->form();
    }

    public function edit(ProductFeature $feature)
    {
        return $this->form($feature);
    }

    private function form(?ProductFeature $feature = null)
    {
        return view('admin.feature-form', ['feature' => $feature,
            'products' => Product::where(fn ($q) => $q->where('status', 'active')->orWhere('id', $feature?->product_id))->orderBy('name')->get()]);
    }

    public function store(Request $request, AuditService $audit)
    {
        return $this->save($request, $audit);
    }

    public function update(Request $request, ProductFeature $feature, AuditService $audit)
    {
        return $this->save($request, $audit, $feature);
    }

    private function save(Request $request, AuditService $audit, ?ProductFeature $feature = null)
    {
        $data = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'key' => ['required', 'regex:/^[a-z][a-z0-9_.-]{0,79}$/D', Rule::unique('product_features')->where('product_id', $request->input('product_id'))->ignore($feature?->id)],
            'name' => 'required|string|max:120', 'category' => 'nullable|string|max:120',
            'description' => 'nullable|string|max:5000', 'active' => 'required|boolean',
            'is_required' => 'required|boolean', 'sort_order' => 'required|integer|min:0|max:100000',
        ]);
        $data['product_id'] = (int) $data['product_id'];
        DB::transaction(function () use ($data, $feature, $audit) {
            Product::whereKey($data['product_id'])->lockForUpdate()->firstOrFail();
            if ($feature) {
                $feature = ProductFeature::whereKey($feature->id)->lockForUpdate()->firstOrFail();
                // Stable identity is essential for future package/dependency references.
                if ($feature->product_id !== $data['product_id'] || $feature->key !== $data['key']) {
                    throw ValidationException::withMessages(['key' => __('ui.feature_identity_locked')]);
                }
                $before = $feature->toArray();
                $feature->update($data);
                $audit->record('feature.updated', $feature, $before, $feature->fresh()->toArray());
            } else {
                $feature = ProductFeature::create($data);
                $audit->record('feature.created', $feature, null, $feature->toArray());
            }
        });

        return redirect()->route('features.index')->with('success', __('ui.saved'));
    }

    public function destroy(ProductFeature $feature, AuditService $audit)
    {
        DB::transaction(function () use ($feature, $audit) {
            Product::withTrashed()->whereKey($feature->product_id)->lockForUpdate()->firstOrFail();
            $feature = ProductFeature::whereKey($feature->id)->lockForUpdate()->firstOrFail();
            if ($feature->licenses()->exists()) {
                throw ValidationException::withMessages(['delete' => __('ui.feature_has_dependencies')]);
            }
            $audit->record('feature.deleted', $feature, $feature->toArray());
            $feature->delete();
        });

        return redirect()->route('features.index')->with('success', __('ui.deleted'));
    }
}
