<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
        $rules = [
            'draft_data' => ['required', 'array', 'max:50'],
            // Campaigns define their own section keys. Validate and retain each
            // configured section while keeping a bounded object shape.
            'draft_data.*' => ['array', 'max:100'],
            'draft_data.personal' => ['sometimes', 'array'],
            'draft_data.address' => ['sometimes', 'array'],
            'draft_data.origin' => ['sometimes', 'array'],
            'draft_data.residence' => ['sometimes', 'array'],
            'draft_data.education' => ['sometimes', 'array', 'max:20'],
            'draft_data.employment' => ['sometimes', 'array', 'max:30'],
            'draft_data.professional_registrations' => ['sometimes', 'array', 'max:20'],
            'draft_data.skills' => ['sometimes', 'array', 'max:30'],
            'draft_data.declarations' => ['sometimes', 'array'],
            'draft_data.declaration' => ['sometimes', 'array'],
            'entity_version' => ['required', 'integer', 'min:1'],
        ];

        foreach (['address', 'origin', 'residence'] as $section) {
            foreach (['region', 'subregion', 'district', 'county', 'subcounty', 'parish', 'village'] as $level) {
                $rules["draft_data.{$section}.{$level}_id"] = [
                    'nullable',
                    'ulid',
                    Rule::exists('administrative_units', 'id')->where(fn ($query) => $query->where('level', $level)->where('active', true)),
                ];
                $rules["draft_data.{$section}.{$level}"] = ['nullable', 'string', 'max:255'];
            }
            $rules["draft_data.{$section}.full_address"] = ['nullable', 'string', 'max:1000'];
            $rules["draft_data.{$section}.physical_address"] = ['nullable', 'string', 'max:1000'];
            $rules["draft_data.{$section}.residence_months"] = ['nullable', 'integer', 'min:0', 'max:1200'];
        }

        return $rules;
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['address', 'origin', 'residence'] as $section) {
                $address = $this->input("draft_data.{$section}");
                if (! is_array($address)) {
                    continue;
                }
                $deepestId = collect(['village', 'parish', 'subcounty', 'county', 'district'])
                    ->map(fn (string $level) => $address["{$level}_id"] ?? null)
                    ->filter()
                    ->first();
                if (! $deepestId) {
                    continue;
                }

                $path = DB::table('administrative_unit_paths')->where('unit_id', $deepestId)->first();
                if (! $path) {
                    $validator->errors()->add("draft_data.{$section}", 'Choose an address from the current administrative unit list.');

                    continue;
                }
                foreach (['region', 'subregion', 'district', 'county', 'subcounty', 'parish', 'village'] as $level) {
                    $expected = $path->{"{$level}_id"};
                    if ($expected && ($address["{$level}_id"] ?? null) !== $expected) {
                        $validator->errors()->add("draft_data.{$section}.{$level}_id", 'The selected administrative units do not belong to the same address path.');
                    }
                }
            }
        }];
    }
}
