<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchAdministrativeUnitsRequest extends FormRequest
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
            'level' => ['required', Rule::in(['region', 'subregion', 'district', 'county', 'subcounty', 'parish', 'village'])],
            'parent_id' => ['nullable', 'ulid', 'exists:administrative_units,id'],
            'region_id' => ['nullable', 'ulid', 'exists:administrative_units,id'],
            'subregion_id' => ['nullable', 'ulid', 'exists:administrative_units,id'],
            'district_id' => ['nullable', 'ulid', 'exists:administrative_units,id'],
            'county_id' => ['nullable', 'ulid', 'exists:administrative_units,id'],
            'subcounty_id' => ['nullable', 'ulid', 'exists:administrative_units,id'],
            'parish_id' => ['nullable', 'ulid', 'exists:administrative_units,id'],
            'search' => ['nullable', 'string', 'min:2', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:250'],
        ];
    }
}
