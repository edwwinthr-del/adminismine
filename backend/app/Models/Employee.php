<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasAuditColumns, HasFactory, Searchable, SoftDeletes;

    protected $table = 'radnici';

    /** Documents whose expiry the office has to watch. */
    public const EXPIRY_FIELDS = [
        'contract_end_date',
        'work_permit_expiry',
        'residence_permit_expiry',
        'medical_exam_expiry',
        'safety_training_expiry',
    ];

    /** Fields treated as "documents on file"; a null one is reported as missing. */
    public const REQUIRED_DOCUMENTS = [
        'passport_number',
        'id_number',
        'contract_start_date',
        'work_permit_expiry',
        'residence_permit_expiry',
        'medical_exam_expiry',
        'safety_training_expiry',
    ];

    /** Default look-ahead window for expiry warnings, in days. */
    public const EXPIRY_WARNING_DAYS = 60;

    public const BANK_ACCOUNT_STATUSES = ['unknown', 'none', 'pending', 'open'];

    public const SALARY_PERIODS = ['monthly', 'daily'];

    public const SALARY_RULES = ['working_days', 'fixed_daily'];

    public const STATUSES = ['active', 'inactive'];

    /** @var list<string> */
    protected array $searchable = ['first_name', 'last_name', 'passport_number', 'id_number'];

    protected $fillable = [
        'first_name',
        'last_name',
        'origin_country',
        'passport_number',
        'id_number',
        'job_role',
        'bank_account_number',
        'bank_name',
        'bank_account_status',
        'base_salary',
        'salary_currency',
        'salary_period',
        'salary_calculation_rule',
        'daily_rate_override',
        'overtime_multiplier',
        'overtime_hourly_rate',
        'contract_start_date',
        'contract_end_date',
        'work_permit_expiry',
        'residence_permit_expiry',
        'medical_exam_expiry',
        'safety_training_expiry',
        'status',
        'source',
        'notes',
    ];

    /** Mirrors the migration defaults so freshly created models report them too. */
    protected $attributes = [
        'bank_account_status' => 'unknown',
        'salary_currency' => 'EUR',
        'salary_period' => 'monthly',
        'salary_calculation_rule' => 'working_days',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'contract_start_date' => 'date:Y-m-d',
            'contract_end_date' => 'date:Y-m-d',
            'work_permit_expiry' => 'date:Y-m-d',
            'residence_permit_expiry' => 'date:Y-m-d',
            'medical_exam_expiry' => 'date:Y-m-d',
            'safety_training_expiry' => 'date:Y-m-d',
            'base_salary' => 'decimal:2',
            'daily_rate_override' => 'decimal:2',
            'overtime_multiplier' => 'decimal:2',
            'overtime_hourly_rate' => 'decimal:2',
        ];
    }

    public function salaryPayments(): HasMany
    {
        return $this->hasMany(SalaryPayment::class)->orderByDesc('salary_month');
    }

    public function worksites(): BelongsToMany
    {
        return $this->belongsToMany(Worksite::class, 'radnik_gradiliste')
            ->withPivot(['assigned_from', 'assigned_to'])
            ->withTimestamps();
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function workerNeeds(): HasMany
    {
        return $this->hasMany(WorkerNeed::class);
    }

    public function houseOccupancies(): HasMany
    {
        return $this->hasMany(HouseOccupancy::class)->orderByDesc('moved_in_at');
    }

    public function housingDeductions(): HasMany
    {
        return $this->hasMany(HousingDeduction::class)->orderByDesc('month');
    }

    public function master(): HasOne
    {
        return $this->hasOne(Master::class);
    }

    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => trim("{$this->first_name} {$this->last_name}"),
        );
    }

    /** Names of the required document fields that are still empty. */
    protected function missingDocuments(): Attribute
    {
        return Attribute::make(
            get: fn (): array => array_values(array_filter(
                self::REQUIRED_DOCUMENTS,
                fn (string $field): bool => $this->{$field} === null,
            )),
        );
    }

    /**
     * Documents already expired or expiring inside the window, newest deadline last.
     *
     * @return list<array{document: string, date: string, days_remaining: int, expired: bool}>
     */
    public function documentAlerts(int $withinDays = self::EXPIRY_WARNING_DAYS): array
    {
        $today = now()->startOfDay();
        $limit = $today->copy()->addDays($withinDays);
        $alerts = [];

        foreach (self::EXPIRY_FIELDS as $field) {
            $date = $this->{$field};

            if ($date === null || $date->startOfDay()->gt($limit)) {
                continue;
            }

            $alerts[] = [
                'document' => $field,
                'date' => $date->toDateString(),
                'days_remaining' => (int) $today->diffInDays($date->startOfDay(), false),
                'expired' => $date->startOfDay()->lt($today),
            ];
        }

        usort($alerts, fn (array $a, array $b): int => $a['days_remaining'] <=> $b['days_remaining']);

        return $alerts;
    }

    /**
     * Daily earned pay basis. `fixed_daily` employees use their override (or the
     * base salary when the period is already daily); the default rule divides the
     * monthly base salary by the month's configured working days.
     */
    public function dailyRate(int $workingDays): ?float
    {
        if ($this->salary_calculation_rule === 'fixed_daily' || $this->salary_period === 'daily') {
            $rate = $this->daily_rate_override ?? ($this->salary_period === 'daily' ? $this->base_salary : null);

            return $rate === null ? null : round((float) $rate, 2);
        }

        if ($this->base_salary === null || $workingDays < 1) {
            return null;
        }

        return round((float) $this->base_salary / $workingDays, 2);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** Employees with a document already expired or expiring within $days. */
    public function scopeWithExpiringDocuments(Builder $query, int $days = self::EXPIRY_WARNING_DAYS): Builder
    {
        $limit = now()->startOfDay()->addDays($days)->toDateString();

        return $query->where(function (Builder $q) use ($limit): void {
            foreach (self::EXPIRY_FIELDS as $field) {
                $q->orWhere(fn (Builder $inner) => $inner
                    ->whereNotNull($field)
                    ->whereDate($field, '<=', $limit));
            }
        });
    }

    /** Employees missing at least one required document. */
    public function scopeMissingDocuments(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            foreach (self::REQUIRED_DOCUMENTS as $field) {
                $q->orWhereNull($field);
            }
        });
    }
}
