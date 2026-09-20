<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Installation;
use App\Models\Release;
use App\Models\SecurityEvent;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MonitoringController extends Controller
{
    public function installations()
    {
        return view('admin.resource', [
            'title' => __('ui.installations'),
            'columns' => ['hostname', 'license.customer.name', 'status', 'application_version', 'last_seen_at', 'last_state_synced_at'],
            'rows' => Installation::with('license.customer')->latest()->paginate(20),
            'showRoute' => 'installations.show',
        ]);
    }

    public function installation(Installation $installation)
    {
        return view('admin.installation-show', [
            'installation' => $installation->load(['license.customer', 'license.product', 'release', 'targetRelease', 'events']),
            'releases' => Release::where('product_id', $installation->product_id)
                ->where('status', 'published')->latest('published_at')->get(),
        ]);
    }

    public function installationStatus(Installation $installation, string $status, AuditService $audit)
    {
        abort_unless(in_array($status, ['active', 'locked'], true), 404);
        DB::transaction(function () use ($installation, $status, $audit): void {
            $before = $installation->toArray();
            $installation->update([
                'status' => $status,
                'locked_at' => $status === 'locked' ? now() : null,
                'lock_reason' => $status === 'locked' ? 'admin' : null,
            ]);
            $installation->license()->update(['state_revision' => DB::raw('state_revision + 1')]);
            $audit->record('installation.'.$status, $installation, $before, $installation->fresh()->toArray());
        });

        return back()->with('success', __('ui.saved'));
    }

    public function targetRelease(Request $request, Installation $installation, AuditService $audit)
    {
        $data = $request->validate(['release_id' => ['nullable', 'exists:releases,id']]);
        $release = empty($data['release_id']) ? null : Release::findOrFail($data['release_id']);
        abort_if($release && ($release->product_id !== $installation->product_id || $release->status->value !== 'published'), 422);

        DB::transaction(function () use ($installation, $release, $audit): void {
            $before = $installation->toArray();
            $installation->update(['target_release_id' => $release?->id]);
            $installation->license()->update(['state_revision' => DB::raw('state_revision + 1')]);
            $audit->record('installation.target_release_changed', $installation, $before, $installation->fresh()->toArray());
        });

        return back()->with('success', __('ui.saved'));
    }

    public function security()
    {
        return view('admin.resource', [
            'title' => __('ui.security_events'),
            'columns' => ['type', 'request_id', 'ip_address', 'occurred_at'],
            'rows' => SecurityEvent::latest('occurred_at')->paginate(20),
        ]);
    }

    public function audit()
    {
        return view('admin.resource', [
            'title' => __('ui.audit_logs'),
            'columns' => ['action', 'subject_type', 'request_id', 'occurred_at'],
            'rows' => AuditLog::latest('occurred_at')->paginate(20),
        ]);
    }
}
