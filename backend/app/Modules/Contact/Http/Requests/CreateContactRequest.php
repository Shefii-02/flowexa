<?php

namespace App\Modules\Contact\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

// ─── Create Contact ───────────────────────────────────────────────────────────
class CreateContactRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'phone'           => ['required', 'string', 'max:20'],
            'name'            => ['nullable', 'string', 'max:100'],
            'email'           => ['nullable', 'email', 'max:150'],
            'custom_fields'   => ['nullable', 'array'],
            'opted_in'        => ['nullable', 'boolean'],
            'label_ids'       => ['nullable', 'array'],
            'label_ids.*'     => ['integer', 'exists:contact_labels,id'],
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
