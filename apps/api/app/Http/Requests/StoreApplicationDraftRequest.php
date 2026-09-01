<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreApplicationDraftRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('application')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'draft_data' => ['required', 'array'],
            'draft_data.personal' => ['sometimes', 'array'],
            'draft_data.origin' => ['sometimes', 'array'],
            'draft_data.residence' => ['sometimes', 'array'],
            'draft_data.education' => ['sometimes', 'array', 'max:20'],
            'draft_data.employment' => ['sometimes', 'array', 'max:30'],
            'draft_data.professional_registrations' => ['sometimes', 'array', 'max:20'],
            'draft_data.skills' => ['sometimes', 'array', 'max:30'],
            'draft_data.declarations' => ['sometimes', 'array'],
            'entity_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
