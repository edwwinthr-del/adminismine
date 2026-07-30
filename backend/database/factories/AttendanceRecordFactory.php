<?php

namespace Database\Factories;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Worksite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    protected $model = AttendanceRecord::class;

    public function definition(): array
    {
        return [
            'date' => now()->toDateString(),
            'employee_id' => Employee::factory(),
            'worksite_id' => Worksite::factory(),
            'master_id' => null,
            'status' => 'present',
            'regular_hours' => 8,
            'overtime_hours' => 0,
            'approval_status' => 'draft',
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'approval_status' => 'approved',
            'approved_at' => now(),
            'approved_for_payroll' => true,
        ]);
    }
}
