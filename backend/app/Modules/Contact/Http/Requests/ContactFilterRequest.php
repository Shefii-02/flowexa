<?php

namespace App\Modules\Contact\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


// ─── Contact Filter ───────────────────────────────────────────────────────────
class ContactFilterRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'search'   => ['nullable', 'string', 'max:100'],
            'label_id' => ['nullable', 'integer', 'exists:contact_labels,id'],
            'opted_in' => ['nullable', 'boolean'],
            'sort_by'  => ['nullable', 'in:name,phone,created_at,last_message_at'],
            'sort_dir' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page'     => ['nullable', 'integer', 'min:1'],
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
