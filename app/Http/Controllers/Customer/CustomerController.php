<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $customers = Customer::query()
            ->when($request->query('search'), fn ($query, $search) => $query->search($search))
            ->orderBy('name')
            ->get();

        return CustomerResource::collection($customers);
    }

    public function show(Customer $customer)
    {
        return new CustomerResource($customer);
    }

    public function store(StoreCustomerRequest $request)
    {
        $customer = Customer::create($request->validated());

        return new CustomerResource($customer);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        $customer->update($request->validated());

        return new CustomerResource($customer);
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();

        return response()->noContent();
    }

    /**
     * Payload de solo lectura para el ConfirmDialog de borrado. Es informativo:
     * DELETE /api/customers/{customer} no se bloquea aunque haya registros asociados.
     */
    public function deleteSummary(Customer $customer)
    {
        $customer->loadCount(['quotes', 'sales']);

        return response()->json([
            'data' => [
                'quotes_count' => $customer->quotes_count,
                'sales_count' => $customer->sales_count,
            ],
        ]);
    }
}
