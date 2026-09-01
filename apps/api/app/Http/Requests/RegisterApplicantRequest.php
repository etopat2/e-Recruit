<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterApplicantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_names' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:100'],
            'nin' => ['required', 'string', 'min:12', 'max:24'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{9,15}$/'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'sex' => ['required', 'string', 'max:30'],
            'nationality' => ['required', 'string', 'max:80'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
        ];
    }
}
