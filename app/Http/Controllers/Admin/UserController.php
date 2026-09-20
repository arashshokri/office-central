<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        return view('admin.users', ['users' => User::latest()->paginate(20)]);
    }

    public function store(Request $request, AuditService $audit)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'role' => ['required', Rule::in(['super_admin', 'admin', 'viewer'])],
        ]);
        $data['email'] = Str::lower(trim($data['email']));
        $user = User::create($data + ['active' => true, 'locale' => 'fa']);
        $audit->record('user.created', $user, null, ['email' => $user->email, 'role' => $user->role]);

        return back()->with('success', __('ui.saved'));
    }

    public function update(Request $request, User $user, AuditService $audit)
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(['super_admin', 'admin', 'viewer'])],
            'active' => ['required', 'boolean'],
        ]);

        if ($user->role === 'super_admin' && ($data['role'] !== 'super_admin' || ! $data['active'])) {
            abort_if(User::where('role', 'super_admin')->where('active', true)->count() <= 1, 422, __('ui.last_super_admin_guard'));
        }
        abort_if($request->user()->is($user) && ! $data['active'], 422, __('ui.cannot_disable_self'));

        $before = $user->only(['role', 'active']);
        $user->update($data);
        $audit->record('user.updated', $user, $before, $user->only(['role', 'active']));

        return back()->with('success', __('ui.saved'));
    }
}
