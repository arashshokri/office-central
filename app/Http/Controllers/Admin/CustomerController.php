<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:255', 'status' => 'nullable|in:active,inactive']);

        return view('admin.resource', ['title' => __('ui.customers'), 'columns' => ['name', 'company_name', 'email', 'phone', 'licenses_count', 'status'],
            'rows' => Customer::withCount('licenses')
                ->when($filters['q'] ?? null, fn ($q, $value) => $q->where(fn ($q) => $q->whereLike('name', '%'.$value.'%')
                    ->orWhereLike('company_name', '%'.$value.'%')->orWhereLike('email', '%'.$value.'%')->orWhereLike('phone', '%'.$value.'%')))
                ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->latest()->paginate(20)->withQueryString(), 'searchScope' => 'customers', 'createRoute' => route('customers.create'),
            'editRoute' => 'customers.edit', 'deleteRoute' => 'customers.destroy']);
    }

    private function form(?Customer $customer = null)
    {
        return view('admin.form', ['title' => __($customer ? 'ui.edit_customer' : 'ui.new_customer'),
            'action' => $customer ? route('customers.update', $customer) : route('customers.store'),
            'method' => $customer ? 'PUT' : 'POST', 'model' => $customer, 'backRoute' => route('customers.index'),
            'fields' => ['name' => 'text', 'company_name' => 'text', 'email' => 'email', 'phone' => 'text', 'notes' => 'textarea', 'status' => 'select:active,inactive']]);
    }

    public function create()
    {
        return $this->form();
    }

    public function edit(Customer $customer)
    {
        return $this->form($customer);
    }

    private function data(Request $request): array
    {
        return $request->validate(['name' => 'required|string|max:255', 'company_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:50', 'notes' => 'nullable|string|max:10000', 'status' => 'required|in:active,inactive']);
    }

    public function store(Request $request, AuditService $audit)
    {
        $customer = Customer::create($this->data($request));
        $audit->record('customer.created', $customer, null, $customer->toArray());

        return redirect()->route('customers.index')->with('success', __('ui.saved'));
    }

    public function update(Request $request, Customer $customer, AuditService $audit)
    {
        $before = $customer->toArray();
        $customer->update($this->data($request));
        $audit->record('customer.updated', $customer, $before, $customer->fresh()->toArray());

        return redirect()->route('customers.index')->with('success', __('ui.saved'));
    }

    public function destroy(Customer $customer, AuditService $audit)
    {
        DB::transaction(function () use ($customer, $audit) {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if ($customer->licenses()->exists() || $customer->installations()->whereHas('license', fn ($q) => $q->whereNull('deleted_at'))->exists()) {
                throw ValidationException::withMessages(['delete' => __('ui.customer_has_dependencies')]);
            }
            $before = $customer->toArray();
            $customer->delete();
            $audit->record('customer.deleted', $customer, $before);
        });

        return redirect()->route('customers.index')->with('success', __('ui.deleted'));
    }
}
