<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SyncEventsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->id === $this->route('offlinePackage')?->user_id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'max:500'],
            'client_pending_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'last_local_sequence' => ['nullable', 'integer', 'min:0'],
            'complete' => ['nullable', 'boolean'],
            'events.*.id' => ['required', 'uuid'],
            'events.*.entity_type' => ['required', 'string', 'max:80'],
            'events.*.entity_id' => ['required', 'string', 'max:64'],
            'events.*.action_type' => ['required', 'string', 'max:80'],
            'events.*.payload_schema_version' => ['required', 'integer', 'min:1', 'max:10'],
            'events.*.payload' => ['required', 'array'],
            'events.*.base_entity_version' => ['required', 'integer', 'min:1'],
            'events.*.local_sequence' => ['required', 'integer', 'min:1'],
            'events.*.local_timestamp' => ['required', 'date'],
        ];
    }
}
