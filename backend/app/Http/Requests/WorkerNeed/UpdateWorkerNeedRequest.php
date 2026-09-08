<?php

namespace App\Http\Requests\WorkerNeed;

use App\Models\WorkerNeed;
use App\Support\Vocabulary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The office reviews, assigns and resolves; the worker and date stay as filed. */
class UpdateWorkerNeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:worker_needs.manage
    }

    public function rules(): array
    {
        return [
            'worksite_id' => ['nullable', 'integer', 'exists:gradilista,id'],
            'need_type' => ['sometimes', Rule::in(Vocabulary::values('worker_need_type'))],
            'description' => ['sometimes', 'string', 'max:2000'],
            'priority' => ['sometimes', Rule::in(WorkerNeed::PRIORITIES)],
            'status' => ['sometimes', Rule::in(WorkerNeed::STATUSES)],
            'assigned_user_id' => ['nullable', 'integer', 'exists:korisnici,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
