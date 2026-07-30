<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Employee::query();

        if ($request->boolean('with_removed')) {
            $query->withTrashed();
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('bank_account_status')) {
            $query->where('bank_account_status', $request->input('bank_account_status'));
        }
        $query->search($request->input('search'));
        if ($request->boolean('expiring')) {
            $query->withExpiringDocuments($request->integer('within_days', Employee::EXPIRY_WARNING_DAYS));
        }
        if ($request->boolean('missing_documents')) {
            $query->missingDocuments();
        }

        $query->orderBy('last_name')->orderBy('first_name');

        return EmployeeResource::collection($query->paginate($request->integer('per_page', 50)));
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = Employee::create($request->validated());

        activity()->performedOn($employee)->causedBy($request->user())->log('employee.created');

        return (new EmployeeResource($employee))->response()->setStatusCode(201);
    }

    public function show(Employee $employee): EmployeeResource
    {
        return new EmployeeResource($employee);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $employee->update($request->validated());

        activity()->performedOn($employee)->causedBy($request->user())->log('employee.updated');

        return new EmployeeResource($employee->fresh());
    }

    /**
     * Removal keeps the record: the employee is deactivated and soft-deleted so
     * salary, attendance and housing history stay intact.
     */
    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        $employee->forceFill(['status' => 'inactive'])->save();
        $employee->delete();

        activity()->performedOn($employee)->causedBy($request->user())->log('employee.removed');

        return response()->json(['message' => 'Employee removed; historical records kept.']);
    }

    public function restore(Request $request, Employee $employee): EmployeeResource
    {
        $employee->restore();

        activity()->performedOn($employee)->causedBy($request->user())->log('employee.restored');

        return new EmployeeResource($employee->fresh());
    }

    /** Employees with documents already expired or expiring inside the window. */
    public function expiringDocuments(Request $request): JsonResponse
    {
        $days = $request->integer('within_days', Employee::EXPIRY_WARNING_DAYS);

        $employees = Employee::query()
            ->active()
            ->withExpiringDocuments($days)
            ->orderBy('last_name')
            ->get();

        $rows = $employees->map(fn (Employee $employee): array => [
            'id' => $employee->id,
            'full_name' => $employee->full_name,
            'job_role' => $employee->job_role,
            'alerts' => $employee->documentAlerts($days),
        ])->values();

        return response()->json([
            'data' => $rows,
            'meta' => ['within_days' => $days, 'count' => $rows->count()],
        ]);
    }
}
