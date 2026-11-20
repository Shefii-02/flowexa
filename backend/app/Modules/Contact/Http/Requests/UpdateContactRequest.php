<?php

namespace App\Modules\Contact\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

// ─── Update Contact ───────────────────────────────────────────────────────────
class UpdateContactRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'          => ['nullable', 'string', 'max:100'],
            'email'         => ['nullable', 'email', 'max:150'],
            'phone'         => ['nullable', 'string', 'max:30'],   // digits are extracted and checked in the service (7–15, not already taken)
            'custom_fields' => ['nullable', 'array'],
            'opted_in'      => ['nullable', 'boolean'],
            'assigned_to'   => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Validation failed',
            'errors'  => $validator->errors(),
        ], 422));
    }
}
