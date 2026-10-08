<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Installation;
use App\Models\License;
use App\Models\Product;
use App\Models\Release;
use App\Services\AuditService;
use App\Services\PackageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReleaseController extends Controller
{
    public function index()
    {
        return view('admin.resource', ['title' => __('ui.releases'), 'columns' => ['product.name', 'version', 'channel', 'status', 'package_sha256'],
            'rows' => Release::with('product')->latest()->paginate(20), 'createRoute' => route('releases.create'),
            'actions' => 'releases', 'editRoute' => 'releases.edit', 'deleteRoute' => 'releases.destroy']);
    }

    private function form(?Release $release = null)
    {
        return view('admin.release-form', ['release' => $release, 'products' => Product::where(fn ($q) => $q->where('status', 'active')->orWhere('id', $release?->product_id))->get()]);
    }

    public function create()
    {
        return $this->form();
    }

    public function edit(Release $release)
    {
        return $this->form($release);
    }

    private function data(Request $request, ?Release $release = null): array
    {
        $published = $release?->status->value === 'published';
        $rules = ['release_notes' => 'nullable|string|max:20000', 'is_security' => 'sometimes|boolean'];
        if ($published) {
            // Published payloads and identities are immutable: changing them
            // would invalidate signatures and receipts on existing customers.
            foreach (['product_id', 'version', 'channel'] as $field) {
                if ($request->has($field) && (string) $request->input($field) !== (string) ($release->$field instanceof \BackedEnum ? $release->$field->value : $release->$field)) {
                    throw ValidationException::withMessages([$field => __('ui.published_release_immutable')]);
                }
            }
            $rules['package'] = 'prohibited';
        } else {
            $rules += ['product_id' => ['required', Rule::exists('products', 'id')->whereNull('deleted_at')],
                'version' => ['required', 'regex:/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?$/',
                    Rule::unique('releases')->where('product_id', $request->input('product_id'))->where('channel', $request->input('channel'))->ignore($release?->id)],
                'channel' => 'required|in:stable,beta,alpha,internal',
                'package' => ($release ? 'nullable' : 'required').'|file|mimes:zip|max:1048576'];
        }
        $data = $request->validate($rules);
        $data['is_security'] = $request->has('is_security') ? $request->boolean('is_security') : ($release?->is_security ?? false);

        return $data;
    }

    private function save(Request $request, AuditService $audit, PackageService $packages, ?Release $release = null)
    {
        $data = $this->data($request, $release);
        $file = $request->file('package');
        $inspection = $file ? $packages->inspect($file) : null;
        $manifest = $file ? ($inspection['runtime_manifest'] ?? null) : $release?->runtime_manifest;
        if ($manifest && ($manifest['version'] ?? null) !== ($data['version'] ?? $release?->version)) {
            throw ValidationException::withMessages(['version' => __('ui.manifest_version_mismatch')]);
        }
        unset($data['package']);
        $newPath = null;
        if ($file) {
            $uuid = (string) Str::uuid();
            $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($file->getClientOriginalName()));
            $newPath = "packages/{$data['product_id']}/{$uuid}/{$name}";
            if (! Storage::disk(config('office.package_disk'))->putFileAs(dirname($newPath), $file, basename($newPath))) {
                throw new \RuntimeException('Package storage failed.');
            }
            $data += ['package_filename' => $name, 'package_path' => $newPath, 'package_size' => $inspection['size'],
                'package_sha256' => $inspection['sha256'], 'runtime_manifest' => $inspection['runtime_manifest'] ?? null];
        }
        try {
            DB::transaction(function () use ($release, $data, $audit, $request) {
                if ($release) {
                    $release = Release::whereKey($release->id)->lockForUpdate()->firstOrFail();
                    $this->data($request, $release);
                    $before = $release->toArray();
                    $release->update($data);
                    $audit->record('release.updated', $release, $before, $release->fresh()->toArray());
                } else {
                    $release = Release::create($data + ['status' => 'draft', 'source_type' => 'manual']);
                    $audit->record('release.created', $release, null, $release->toArray());
                }
            });
        } catch (\Throwable $error) {
            if ($newPath) {
                Storage::disk(config('office.package_disk'))->delete($newPath);
            }
            throw $error;
        }

        return redirect()->route('releases.index')->with('success', __('ui.saved'));
    }

    public function store(Request $request, AuditService $audit, PackageService $packages)
    {
        return $this->save($request, $audit, $packages);
    }

    public function update(Request $request, Release $release, AuditService $audit, PackageService $packages)
    {
        return $this->save($request, $audit, $packages, $release);
    }

    public function publish(Release $release, AuditService $audit)
    {
        abort_if($release->status->value !== 'draft' || ! $release->package_path, 409);
        DB::transaction(function () use ($release, $audit) {
            $before = $release->toArray();
            $release->update(['status' => 'published', 'published_at' => now()]);
            $audit->record('release.published', $release, $before, $release->fresh()->toArray());
        });

        return back()->with('success', __('ui.published'));
    }

    public function destroy(Release $release, AuditService $audit)
    {
        DB::transaction(function () use ($release, $audit) {
            $release = Release::whereKey($release->id)->lockForUpdate()->firstOrFail();
            if (License::where(fn ($q) => $q->where('release_id', $release->id)->orWhere('update_release_id', $release->id))->exists()
                || Installation::where(fn ($q) => $q->where('release_id', $release->id)->orWhere('target_release_id', $release->id))
                    ->whereHas('license', fn ($q) => $q->whereNull('deleted_at'))->exists()) {
                throw ValidationException::withMessages(['delete' => __('ui.release_has_dependencies')]);
            }
            $before = $release->toArray();
            $release->delete();
            $audit->record('release.deleted', $release, $before);
        });

        return redirect()->route('releases.index')->with('success', __('ui.deleted'));
    }
}
