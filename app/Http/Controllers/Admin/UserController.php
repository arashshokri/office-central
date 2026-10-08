<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index()
    {
        return view('admin.users', ['users' => User::latest()->paginate(20), 'total' => User::count(),
            'activeCount' => User::where('active', true)->count(), 'twoFactorCount' => User::whereNotNull('two_factor_confirmed_at')->count()]);
    }

    public function create()
    {
        return view('admin.user-form', ['managedUser' => null]);
    }

    public function edit(User $user)
    {
        return view('admin.user-form', ['managedUser' => $user]);
    }

    public function store(Request $request, AuditService $audit)
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'role' => ['required', Rule::in(['super_admin', 'admin', 'viewer'])],
        ]);
        $data['email'] = Str::lower(trim($data['email']));
        $user = User::create($data + ['active' => true, 'locale' => 'fa']);
        $audit->record('user.created', $user, null, ['email' => $user->email, 'role' => $user->role]);

        return redirect()->route('users.index')->with('success', __('ui.saved'));
    }

    public function update(Request $request, User $user, AuditService $audit)
    {
        if ($request->has('email')) {
            $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:12', 'confirmed'],
            'role' => ['required', Rule::in(['super_admin', 'admin', 'viewer'])],
            'active' => ['required', 'boolean'],
        ]);
        if (empty($data['password'])) {
            unset($data['password']);
        }

        DB::transaction(function () use ($request, $user, $data, $audit) {
            // Serialize changes to administrator access, including simultaneous demotions.
            $administrators = User::where('role', 'super_admin')->where('active', true)->orderBy('id')->lockForUpdate()->get();
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($user->role === 'super_admin' && ($data['role'] !== 'super_admin' || ! $data['active']) && $administrators->count() <= 1) {
                throw ValidationException::withMessages(['role' => __('ui.last_super_admin_guard')]);
            }
            if ($request->user()->is($user) && ! $data['active']) {
                throw ValidationException::withMessages(['active' => __('ui.cannot_disable_self')]);
            }
            $before = $user->only(['name', 'email', 'role', 'active']);
            $user->update($data);
            $audit->record('user.updated', $user, $before, $user->only(['name', 'email', 'role', 'active']));
        });

        return redirect()->route('users.index')->with('success', __('ui.saved'));
    }
}
