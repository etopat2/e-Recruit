<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchEducationInstitutionsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'level' => ['required', 'string', Rule::in(config('erecruit.institution_directory.levels'))],
            'search' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.config('erecruit.institution_directory.maximum_results')],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'level' => trim((string) $this->input('level')),
            'search' => trim((string) $this->input('search')),
        ]);
    }
}
