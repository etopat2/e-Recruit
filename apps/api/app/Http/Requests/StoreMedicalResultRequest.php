<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreMedicalResultRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasRole('medical_officer') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'application_id' => ['required', 'exists:applications,id'],
            'medical_schedule_id' => ['required', 'exists:medical_schedules,id'],
            'outcome' => ['required', 'in:Fit,Not Fit,Deferred,Further Assessment Required,No Show'],
            'restricted_notes' => ['nullable', 'string', 'max:4000'],
            'clinical_reference' => ['nullable', 'string', 'max:255'],
            'entity_version' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
