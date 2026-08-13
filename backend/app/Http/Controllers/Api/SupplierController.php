<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\StoreSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Supplier::query();

        $query->search($request->input('search'));

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $suppliers = $query->orderBy('name')->limit(200)->get();

        return response()->json(['data' => SupplierResource::collection($suppliers)]);
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = Supplier::create($request->validated());

        // Every payable points at one of these rows, so creating a supplier is
        // creating something money can be routed to. It was the only
        // write-capable controller left with no trail behind it (rule 3).
        activity()->performedOn($supplier)->causedBy($request->user())
            ->withProperties(['name' => $supplier->name])
            ->log('supplier.created');

        return response()->json(['data' => new SupplierResource($supplier)], 201);
    }
}
