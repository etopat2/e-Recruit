<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVerificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasRole('verification_officer', 'data_clerk', 'hq_recruitment_administrator') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'field_key' => ['required', 'string', 'max:120'],
            'action' => ['required', Rule::in(['verify', 'flag_discrepancy', 'correct', 'mark_ocr_incorrect', 'request_replacement', 'mark_unreadable', 'mark_not_present'])],
            'outcome' => ['required', Rule::in(['VERIFIED/CONSISTENT', 'PROBABLE MATCH', 'DISCREPANCY', 'UNREADABLE/LOW CONFIDENCE', 'NOT AVAILABLE'])],
            'verified_value' => ['nullable'],
            'evidence_references' => ['required_if:action,verify,correct', 'array'],
            'reason' => ['required_unless:action,verify', 'nullable', 'string', 'max:2000'],
            'review_state' => ['nullable', 'array'],
        ];
    }
}
