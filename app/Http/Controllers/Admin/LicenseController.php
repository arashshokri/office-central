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

class LicenseController extends Controller
{
    public function index()
    {
        return view('admin.resource', [
            'title' => __('ui.licenses'),
            'columns' => ['license_key_prefix', 'customer.name', 'product.name', 'status', 'state_revision', 'expires_at'],
            'rows' => License::with(['customer', 'product'])->latest()->paginate(20),
            'createRoute' => route('licenses.create'),
            'showRoute' => 'licenses.show',
        ]);
    }

    public function show(License $license)
    {
        return view('admin.license-show', [
            'license' => $license->load(['customer', 'product', 'release', 'installations']),
        ]);
    }

    public function create()
    {
        return view('admin.license-form', [
            'customers' => Customer::where('status', 'active')->get(),
            'products' => Product::where('status', 'active')->get(),
            'releases' => Release::where('status', 'published')->get(),
        ]);
    }

    public function store(Request $request, LicenseKeyService $keys, AuditService $audit)
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'product_id' => ['required', 'exists:products,id'],
            'release_id' => ['nullable', 'exists:releases,id'],
            'max_installations' => ['required', 'integer', 'min:1', 'max:10000'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        if (! empty($data['release_id'])) {
            abort_unless(Release::whereKey($data['release_id'])->where('product_id', $data['product_id'])->exists(), 422);
        }

        $raw = $keys->generate();
        $license = DB::transaction(fn () => License::create(array_merge($data, [
            'license_key_hash' => $keys->hash($raw),
            'license_key_prefix' => $keys->prefix($raw),
            'status' => 'created',
            'state_revision' => 1,
            'created_by' => $request->user()->id,
        ])));
        $audit->record('license.generated', $license, null, ['uuid' => $license->uuid, 'status' => 'created']);

        return redirect()->route('licenses.show', $license)
            ->with('license_key', $raw)
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
