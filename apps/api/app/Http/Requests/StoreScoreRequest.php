<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreScoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasRole('panel_member', 'panel_head', 'written_examination_officer') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'interview_assignment_id' => ['required', 'exists:interview_assignments,id'],
            'assessment_definition_id' => ['required', 'exists:assessment_definitions,id'],
            'score' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'entity_version' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
