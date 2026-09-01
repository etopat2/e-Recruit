<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubmitApplicationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('submit', $this->route('application')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'entity_version' => ['required', 'integer', 'min:1'],
            'privacy_accepted' => ['accepted'],
            'declaration_accepted' => ['accepted'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
