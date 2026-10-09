<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\License;
use App\Models\Product;
use App\Models\ProductFeature;
use App\Models\Release;
use App\Services\AuditService;
use App\Services\LicenseFeaturePlan;
use App\Services\LicenseKeyService;
use App\Services\OfficeReleaseReadiness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LicenseController extends Controller
{
    public function index(Request $request, LicenseKeyService $keys)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:255', 'product_id' => 'nullable|integer',
            'status' => 'nullable|in:created,active,suspended,expired,revoked']);
        $rows = License::with(['customer', 'product', 'release', 'updateRelease', 'installations'])->withCount('installations')
            ->when($filters['q'] ?? null, fn ($q, $value) => $q->where(fn ($q) => $q
                ->whereLike('display_name', '%'.$value.'%')->orWhereLike('license_key_prefix', '%'.$value.'%')
                ->orWhere('license_key_hash', $keys->hash($value))->orWhereLike('edition', '%'.$value.'%')
                ->orWhereHas('customer', fn ($q) => $q->whereLike('name', '%'.$value.'%')->orWhereLike('company_name', '%'.$value.'%'))
                ->orWhereHas('installations', fn ($q) => $q->whereLike('application_version', '%'.$value.'%'))))
            ->when($filters['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest()->paginate(20)->withQueryString();

        return response()->view('admin.resource', [
            'title' => __('ui.licenses'),
            'columns' => ['display_name', 'customer.name', 'customer.company_name', 'edition', auth()->user()->role === 'viewer' ? 'license_key_prefix' : 'license_key_encrypted', 'installations_count', 'installed_versions', 'updateRelease.version', 'status', 'expires_at'],
            'rows' => $rows,
            'searchScope' => 'licenses', 'products' => Product::orderBy('name')->get(),
            'createRoute' => route('licenses.create'),
            'showRoute' => 'licenses.show',
            'deleteRoute' => 'licenses.destroy',
            'versionRoute' => 'licenses.show',
            'editRoute' => 'licenses.edit',
        ])->header('Cache-Control', 'no-store, private');
    }

    public function show(License $license)
    {
        $updates = Release::with('product')->where('product_id', $license->product_id)->get();
        $updates->each(fn ($release) => app(OfficeReleaseReadiness::class)->inspect($release));

        return response()->view('admin.license-show', [
            'license' => $license->load(['customer', 'product', 'release', 'updateRelease', 'installations', 'features']),
            'plannedFeatures' => $license->plannedFeatures()->get(),
            'updateReleases' => $updates
                ->sort(fn ($a, $b) => version_compare($b->version, $a->version))->values(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function create()
    {
        $releases = Release::with('product')->where('status', 'published')->get();
        $releases->each(fn ($release) => app(OfficeReleaseReadiness::class)->inspect($release));

        return view('admin.license-form', [
            'customers' => Customer::where('status', 'active')->get(),
            'products' => Product::where('status', 'active')->get(),
            'releases' => $releases,
            'hasOfficeRuntime' => $releases->contains(fn ($release) => $release->product?->slug === 'office' && $release->isOfficeUpdateReady()),
            'features' => ProductFeature::where('active', true)->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function edit(License $license)
    {
        return response()->view('admin.license-edit', [
            'license' => $license->load(['customer', 'product', 'features']),
            'features' => ProductFeature::where('product_id', $license->product_id)
                ->where(fn ($q) => $q->where('active', true)->orWhereHas('licenses', fn ($q) => $q->whereKey($license->id)))
                ->orderBy('sort_order')->orderBy('name')->get(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, License $license, LicenseFeaturePlan $plans, AuditService $audit)
    {
        $data = $plans->data($request) + $request->validate(['expires_at' => 'nullable|date']);
        $featureIds = array_map('intval', $data['features'] ?? []);
        $changePlan = $request->has('planned_feature_policy') || $request->has('features');
        unset($data['features']);
        DB::transaction(function () use ($license, $data, $featureIds, $changePlan, $plans, $audit) {
            $license = License::whereKey($license->id)->lockForUpdate()->firstOrFail();
            $before = $license->toArray() + ['features' => $license->features()->pluck('product_features.id')->all()];
            $license->update($data + ['state_revision' => DB::raw('state_revision + 1')]);
            if ($changePlan) {
                $plans->sync($license, $featureIds);
            }
            $audit->record('license.updated', $license, $before, $license->fresh()->toArray() + ['features' => $license->features()->pluck('product_features.id')->all()]);
        });

        return redirect()->route('licenses.show', $license)->with('success', __('ui.saved'));
    }

    public function store(Request $request, LicenseKeyService $keys, LicenseFeaturePlan $plans, AuditService $audit)
    {
        $url = trim((string) $request->input('deployment.app_url', ''));
        if ($url !== '') {
            // The public URL is HTTPS; the Docker upstream may still use HTTP.
            if (! str_contains($url, '://')) {
                $url = 'https://'.$url;
            }
            $url = preg_replace('~^http://~i', 'https://', $url);
            $request->merge(['deployment' => array_replace((array) $request->input('deployment', []), ['app_url' => $url])]);
        }
        $data = $request->validate([
            'activation_mode' => ['nullable', 'in:legacy,installer_once,attach_once'],
            'deployment.app_url' => ['nullable', 'required_if:activation_mode,installer_once', 'url:https', 'max:255'],
            'deployment.admin_email' => ['nullable', 'required_if:activation_mode,installer_once', 'email', 'max:255'],
            'deployment.admin_name' => ['nullable', 'string', 'max:100'],
            'deployment.bind_ip' => ['nullable', 'ip'],
            'deployment.port' => ['nullable', 'integer', 'min:1024', 'max:65535'],
            'deployment.proxy_network' => ['nullable', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D'],
            'customer_id' => ['required', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'product_id' => ['required', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'release_id' => ['nullable', Rule::exists('releases', 'id')->whereNull('deleted_at')],
            'max_installations' => ['required', 'integer', 'min:1', 'max:10000'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ], [
            'deployment.app_url.url' => __('ui.customer_url_invalid'),
            'deployment.app_url.required_if' => __('ui.customer_url_required'),
            'deployment.admin_email.required_if' => __('ui.admin_email_required'),
            'deployment.admin_email.email' => __('ui.admin_email_invalid'),
            'expires_at.date' => __('ui.expiry_invalid'),
            'expires_at.after' => __('ui.expiry_after_today'),
        ]);

        if (! empty($data['release_id'])) {
            abort_unless(Release::whereKey($data['release_id'])->where('product_id', $data['product_id'])->exists(), 422);
        }

        if (in_array($data['activation_mode'] ?? 'legacy', ['installer_once', 'attach_once'], true)) {
            $release = Release::whereKey($data['release_id'] ?? 0)->first();
            if ($release) {
                app(OfficeReleaseReadiness::class)->inspect($release);
            }
            if ($data['activation_mode'] === 'installer_once' && (! $release?->isOfficeUpdateReady() || $release->product->slug !== 'office')) {
                throw ValidationException::withMessages(['release_id' => __('ui.protected_release_required')]);
            }
            if (Product::find($data['product_id'])?->slug !== 'office') {
                throw ValidationException::withMessages(['product_id' => __('ui.office_product_required')]);
            }
            $data['max_installations'] = 1;
            $data['deployment']['port'] = (int) ($data['deployment']['port'] ?? 8080);
            $data['deployment_config'] = $data['deployment'];
        }
        unset($data['deployment']);
        $plan = $plans->data($request);
        $featureIds = array_map('intval', $plan['features'] ?? []);
        unset($plan['features']);
        $raw = $keys->generate();
        $license = DB::transaction(function () use ($data, $plan, $featureIds, $plans, $keys, $raw, $request, $audit) {
            // Lock before inserting the FK, avoiding concurrent PostgreSQL
            // key-share to update-lock upgrades during capability validation.
            Product::whereKey($data['product_id'])->lockForUpdate()->firstOrFail();
            $license = License::create(array_merge($data, $plan, [
                'license_key_hash' => $keys->hash($raw),
                'license_key_prefix' => $keys->prefix($raw),
                'license_key_encrypted' => $raw,
                'status' => 'created',
                'state_revision' => 1,
                'created_by' => $request->user()->id,
            ]));
            $plans->sync($license, $featureIds);
            $audit->record('license.generated', $license, null, ['uuid' => $license->uuid, 'status' => 'created', 'features' => $featureIds]);

            return $license;
        });

        return redirect()->route('licenses.show', $license)
            ->with('success', __('ui.license_once'));
    }

    public function status(License $license, string $status, AuditService $audit)
    {
        abort_unless(in_array($status, ['active', 'suspended', 'revoked'], true), 404);
        $before = $license->toArray();
        $license->update(['status' => $status, 'state_revision' => DB::raw('state_revision + 1')]);
        $audit->record('license.'.$status, $license, $before, $license->fresh()->toArray());

        return back()->with('success', __('ui.saved'));
    }

    public function updateRelease(Request $request, License $license, AuditService $audit)
    {
        $data = $request->validate(['release_id' => ['nullable', Rule::exists('releases', 'id')->whereNull('deleted_at')]]);
        $release = empty($data['release_id']) ? null : Release::findOrFail($data['release_id']);
        if ($release) {
            app(OfficeReleaseReadiness::class)->inspect($release);
        }
        if ($release && ($release->product_id !== $license->product_id || ! $release->isOfficeUpdateReady())) {
            throw ValidationException::withMessages(['release_id' => __('ui.update_requires_runtime')]);
        }
        DB::transaction(function () use ($license, $release, $audit) {
            $license = License::whereKey($license->id)->lockForUpdate()->firstOrFail();
            if ($release) {
                $release = Release::whereKey($release->id)->lockForUpdate()->firstOrFail();
                if ($release->product_id !== $license->product_id || ! $release->isOfficeUpdateReady()) {
                    throw ValidationException::withMessages(['release_id' => __('ui.update_requires_runtime')]);
                }
            }
            $before = $license->toArray();
            $license->update(['update_release_id' => $release?->id, 'state_revision' => DB::raw('state_revision + 1')]);
            // Selecting the license version must also replace previous per-install
            // targets; those otherwise silently take precedence over this grant.
            $cleared = $license->installations()->whereNotNull('target_release_id')->update(['target_release_id' => null]);
            $audit->record('license.update_permission_changed', $license, $before, $license->fresh()->toArray() + ['installation_targets_cleared' => $cleared]);
        }, 5);

        return back()->with('success', __('ui.customer_update_saved'));
    }

    public function replaceCode(License $license, LicenseKeyService $keys, AuditService $audit)
    {
        DB::transaction(function () use ($license, $keys, $audit) {
            $license = License::whereKey($license->id)->lockForUpdate()->firstOrFail();
            abort_if($license->license_key_encrypted !== null, 409);
            $raw = $keys->generate();
            $license->update(['license_key_hash' => $keys->hash($raw), 'license_key_prefix' => $keys->prefix($raw), 'license_key_encrypted' => $raw]);
            $audit->record('license.code_replaced', $license, null, ['installation_bindings_preserved' => true]);
        });

        return back()->with('success', __('ui.license_code_replaced'));
    }

    public function destroy(License $license, AuditService $audit)
    {
        DB::transaction(function () use ($license, $audit) {
            $license = License::whereKey($license->id)->lockForUpdate()->firstOrFail();
            $before = $license->toArray();
            $license->update(['status' => 'revoked', 'state_revision' => DB::raw('state_revision + 1')]);
            $license->delete();
            $audit->record('license.deleted', $license, $before, ['status' => 'revoked']);
        });

        return redirect()->route('licenses.index')->with('success', __('ui.license_deleted'));
    }

    public function temporaryLock(Request $request, License $license, AuditService $audit)
    {
        $data = $request->validate(['message' => ['nullable', 'string', 'max:500']]);
        $before = $license->toArray();
        $license->update([
            'temporarily_locked_at' => now(),
            'temporarily_unlocked_at' => null,
            'temporary_lock_message' => $data['message'] ?: config('office.default_lock_message'),
            'temporary_locked_by' => $request->user()->id,
            'state_revision' => DB::raw('state_revision + 1'),
        ]);
        $audit->record('license.temporary_locked', $license, $before, $license->fresh()->toArray());

        return back()->with('success', __('ui.temporary_lock_enabled'));
    }

    public function temporaryUnlock(License $license, AuditService $audit)
    {
        $before = $license->toArray();
        $license->update([
            'temporarily_locked_at' => null,
            'temporarily_unlocked_at' => now(),
            'temporary_lock_message' => null,
            'temporary_locked_by' => null,
            'state_revision' => DB::raw('state_revision + 1'),
        ]);
        $audit->record('license.temporary_unlocked', $license, $before, $license->fresh()->toArray());

        return back()->with('success', __('ui.temporary_lock_disabled'));
    }
}
