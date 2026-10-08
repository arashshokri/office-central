<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\License;
use App\Models\Product;
use App\Models\Release;
use App\Services\AuditService;
use App\Services\LicenseKeyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LicenseController extends Controller
{
    public function index()
    {
        return response()->view('admin.resource', [
            'title' => __('ui.licenses'),
            'columns' => [auth()->user()->role === 'viewer' ? 'license_key_prefix' : 'license_key_encrypted', 'customer.name', 'product.name', 'status', 'state_revision', 'expires_at'],
            'rows' => License::with(['customer', 'product'])->latest()->paginate(20),
            'createRoute' => route('licenses.create'),
            'showRoute' => 'licenses.show',
            'deleteRoute' => 'licenses.destroy',
        ])->header('Cache-Control', 'no-store, private');
    }

    public function show(License $license)
    {
        return response()->view('admin.license-show', [
            'license' => $license->load(['customer', 'product', 'release', 'updateRelease', 'installations']),
            'updateReleases' => Release::where('product_id', $license->product_id)->where('status', 'published')
                ->whereNotNull('runtime_manifest')->latest('published_at')->get(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function create()
    {
        return view('admin.license-form', [
            'customers' => Customer::where('status', 'active')->get(),
            'products' => Product::where('status', 'active')->get(),
            'releases' => Release::with('product')->where('status', 'published')->get(),
            'hasOfficeRuntime' => Release::where('status', 'published')->whereNotNull('runtime_manifest')
                ->whereHas('product', fn ($query) => $query->where('slug', 'office')->where('status', 'active'))->exists(),
        ]);
    }

    public function store(Request $request, LicenseKeyService $keys, AuditService $audit)
    {
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
        ]);

        if (! empty($data['release_id'])) {
            abort_unless(Release::whereKey($data['release_id'])->where('product_id', $data['product_id'])->exists(), 422);
        }

        if (in_array($data['activation_mode'] ?? 'legacy', ['installer_once', 'attach_once'], true)) {
            $release = Release::whereKey($data['release_id'] ?? 0)->first();
            if ($data['activation_mode'] === 'installer_once' && ($release?->status->value !== 'published' || ! $release->runtime_manifest || $release->product->slug !== 'office')) {
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
        $raw = $keys->generate();
        $license = DB::transaction(fn () => License::create(array_merge($data, [
            'license_key_hash' => $keys->hash($raw),
            'license_key_prefix' => $keys->prefix($raw),
            'license_key_encrypted' => $raw,
            'status' => 'created',
            'state_revision' => 1,
            'created_by' => $request->user()->id,
        ])));
        $audit->record('license.generated', $license, null, ['uuid' => $license->uuid, 'status' => 'created']);

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
        if ($release && ($release->product_id !== $license->product_id || $release->status->value !== 'published' || ! $release->runtime_manifest)) {
            throw ValidationException::withMessages(['release_id' => __('ui.update_requires_runtime')]);
        }
        $before = $license->toArray();
        $license->update(['update_release_id' => $release?->id, 'state_revision' => DB::raw('state_revision + 1')]);
        $audit->record('license.update_permission_changed', $license, $before, $license->fresh()->toArray());

        return back()->with('success', __('ui.saved'));
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
