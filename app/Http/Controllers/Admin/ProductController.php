<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Installation;
use App\Models\License;
use App\Models\Product;
use App\Models\RepositoryIntegration;
use App\Services\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index()
    {
        return view('admin.resource', ['title' => __('ui.products'), 'columns' => ['name', 'slug', 'status'],
            'rows' => Product::latest()->paginate(20), 'createRoute' => route('products.create'),
            'editRoute' => 'products.edit', 'deleteRoute' => 'products.destroy']);
    }

    private function form(?Product $product = null)
    {
        return view('admin.form', ['title' => __($product ? 'ui.edit_product' : 'ui.new_product'),
            'action' => $product ? route('products.update', $product) : route('products.store'),
            'method' => $product ? 'PUT' : 'POST', 'model' => $product, 'backRoute' => route('products.index'),
            'fields' => ['name' => 'text', 'slug' => 'text', 'description' => 'textarea', 'status' => 'select:active,inactive']]);
    }

    public function create()
    {
        return $this->form();
    }

    public function edit(Product $product)
    {
        return $this->form($product);
    }

    private function data(Request $request, ?Product $product = null): array
    {
        $request->merge(['slug' => trim((string) $request->input('slug')) ?: Str::slug((string) $request->input('name'))]);
        $unique = Rule::unique('products', 'slug')->ignore($product?->id);
        if (! $product) {
            // Creation restores a deleted identity. Editing another product must
            // still reject that reserved slug to keep both histories separate.
            $unique->whereNull('deleted_at');
        }
        $data = $request->validate(['name' => 'required|string|max:255', 'slug' => ['required', 'alpha_dash', 'max:100', $unique],
            'description' => 'nullable|string|max:10000', 'status' => 'required|in:active,inactive'],
            ['slug.unique' => __('ui.product_slug_taken')]);
        if ($product && $data['slug'] !== $product->slug && (License::withTrashed()->where('product_id', $product->id)->exists() || $product->features()->exists() || $product->releases()->withTrashed()->exists() || RepositoryIntegration::where('product_id', $product->id)->exists())) {
            throw ValidationException::withMessages(['slug' => __('ui.product_slug_locked')]);
        }

        return $data;
    }

    public function store(Request $request, AuditService $audit)
    {
        $data = $this->data($request);
        $restored = false;
        try {
            DB::transaction(function () use ($data, $audit, &$restored) {
                $product = Product::withTrashed()->where('slug', $data['slug'])->lockForUpdate()->first();
                if ($product) {
                    if (! $product->trashed()) {
                        throw ValidationException::withMessages(['slug' => __('ui.product_slug_taken')]);
                    }
                    $before = $product->toArray();
                    $product->fill($data);
                    $product->restore();
                    // Restore only the product: revoked licenses and deleted
                    // releases retain their state and original foreign keys.
                    $audit->record('product.restored', $product, $before, $product->fresh()->toArray());
                    $restored = true;
                } else {
                    $product = Product::create($data);
                    $audit->record('product.created', $product, null, $product->toArray());
                }
            });
        } catch (UniqueConstraintViolationException $error) {
            // Another administrator may submit this slug between validation and
            // insertion. Keep the DB unique constraint and return a form error.
            if (! Product::withTrashed()->where('slug', $data['slug'])->exists()) {
                throw $error;
            }
            throw ValidationException::withMessages(['slug' => __('ui.product_slug_taken')]);
        }

        return redirect()->route('products.index')->with('success', __($restored ? 'ui.product_restored' : 'ui.saved'));
    }

    public function update(Request $request, Product $product, AuditService $audit)
    {
        $before = $product->toArray();
        $product->update($this->data($request, $product));
        $audit->record('product.updated', $product, $before, $product->fresh()->toArray());

        return redirect()->route('products.index')->with('success', __('ui.saved'));
    }

    public function destroy(Product $product, AuditService $audit)
    {
        DB::transaction(function () use ($product, $audit) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($product->features()->exists() || $product->releases()->exists() || License::where('product_id', $product->id)->exists()
                || RepositoryIntegration::where('product_id', $product->id)->exists()
                || Installation::where('product_id', $product->id)->whereHas('license', fn ($q) => $q->whereNull('deleted_at'))->exists()) {
                throw ValidationException::withMessages(['delete' => __('ui.product_has_dependencies')]);
            }
            $before = $product->toArray();
            $product->delete();
            $audit->record('product.deleted', $product, $before);
        });

        return redirect()->route('products.index')->with('success', __('ui.deleted'));
    }
}
