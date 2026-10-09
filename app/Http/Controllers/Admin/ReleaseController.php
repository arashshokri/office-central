<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Installation;
use App\Models\License;
use App\Models\Product;
use App\Models\Release;
use App\Services\AuditService;
use App\Services\OfficeReleaseReadiness;
use App\Services\OfficeSourceService;
use App\Services\PackageService;
use App\Services\ReleaseUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReleaseController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:255', 'product_id' => 'nullable|integer',
            'status' => 'nullable|in:draft,published', 'channel' => 'nullable|in:stable,beta,alpha,internal', 'kind' => 'nullable|in:runtime,source,security']);

        return view('admin.releases', [
            'rows' => Release::with('product')
                ->when($filters['q'] ?? null, fn ($q, $value) => $q->where(fn ($q) => $q->whereLike('version', '%'.$value.'%')
                    ->orWhereLike('release_notes', '%'.$value.'%')->orWhereHas('product', fn ($q) => $q->whereLike('name', '%'.$value.'%'))))
                ->when($filters['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id))
                ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->when($filters['channel'] ?? null, fn ($q, $channel) => $q->where('channel', $channel))
                ->when($filters['kind'] ?? null, function ($q, $kind) {
                    return match ($kind) {
                        'security' => $q->where('is_security', true),
                        'runtime' => $q->whereNotNull('runtime_manifest'),
                        'source' => $q->whereNull('runtime_manifest'),
                    };
                })->latest()->paginate(20)->withQueryString()->through(function ($release) {
                    app(OfficeReleaseReadiness::class)->inspect($release);

                    return $release;
                }),
            'products' => Product::orderBy('name')->get(), 'searchScope' => 'releases',
        ]);
    }

    public function show(Release $release)
    {
        $release->load('product');
        $readinessError = app(OfficeReleaseReadiness::class)->inspect($release);

        return view('admin.release-show', compact('release', 'readinessError'));
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
        $data = $request->validate($rules, ['version.unique' => __('ui.release_version_exists'),
            'package.max' => __('ui.upload_tooLarge'), 'package.mimes' => __('ui.zip_required'),
            'package.uploaded' => __('ui.upload_php_failed')]);
        $data['is_security'] = $request->has('is_security') ? $request->boolean('is_security') : ($release?->is_security ?? false);

        return $data;
    }

    private function save(Request $request, AuditService $audit, PackageService $packages, ?Release $release = null)
    {
        $data = $this->data($request, $release);
        $file = $request->file('package');
        try {
            $inspection = $file ? $packages->inspect($file) : null;
            if ($file && ! ($inspection['runtime_manifest'] ?? $inspection['source_manifest'] ?? null)
                && Product::find($data['product_id'])?->supportsOfficeHelper()) {
                $inspection['source_manifest'] = app(OfficeSourceService::class)->inspect($file);
            }
        } catch (\InvalidArgumentException|\JsonException $error) {
            throw ValidationException::withMessages(['package' => __('ui.invalid_package', ['reason' => $error->getMessage()])]);
        }
        $manifest = $file ? ($inspection['runtime_manifest'] ?? null) : $release?->runtime_manifest;
        $manifest ??= $file ? ($inspection['source_manifest'] ?? null) : $release?->source_manifest;
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
                Storage::disk(config('office.package_disk'))->delete($newPath);
                throw ValidationException::withMessages(['package' => __('ui.package_storage_failed')]);
            }
            $data += ['package_filename' => $name, 'package_path' => $newPath, 'package_size' => $inspection['size'],
                'package_sha256' => $inspection['sha256'], 'runtime_manifest' => $inspection['runtime_manifest'] ?? null,
                'source_manifest' => $inspection['source_manifest'] ?? null];
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

        if ($request->expectsJson()) {
            $request->session()->flash('success', __('ui.saved'));

            return response()->json(['message' => __('ui.saved'), 'redirect' => route('releases.index')]);
        }

        return redirect()->route('releases.index')->with('success', __('ui.saved'));
    }

    public function store(Request $request, AuditService $audit, PackageService $packages)
    {
        if ($request->filled('package_upload')) {
            abort_unless($request->expectsJson(), 422);

            return app(ReleaseUploadService::class)->finish($request, null, fn () => $this->save($request, $audit, $packages));
        }

        return $this->save($request, $audit, $packages);
    }

    public function update(Request $request, Release $release, AuditService $audit, PackageService $packages)
    {
        if ($request->filled('package_upload')) {
            abort_unless($request->expectsJson(), 422);

            return app(ReleaseUploadService::class)->finish($request, $release->id, fn () => $this->save($request, $audit, $packages, $release));
        }

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
