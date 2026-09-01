<?php

namespace App\Http\Requests;

use App\Models\SelectionRun;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RunSelectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', SelectionRun::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ranking_run_id' => ['required', 'exists:ranking_runs,id'],
            'policy' => ['required', 'array'],
            'policy.total_slots' => ['required', 'integer', 'min:0', 'max:1000000'],
            'policy.reserve_size' => ['required', 'integer', 'min:0', 'max:100000'],
            'policy.bucket_field' => ['nullable', 'string', 'max:80'],
            'policy.quotas' => ['nullable', 'array'],
            'policy.skill_reservations' => ['nullable', 'array'],
            'policy.tie_breakers' => ['nullable', 'array', 'max:10'],
            'mode' => ['required', 'in:scenario,official'],
        ];
    }
}
