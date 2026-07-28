<?php

namespace App\Modules\Staff\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

// ─── Staff Filter ─────────────────────────────────────────────────────────────
class StaffFilterRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'search'     => ['nullable', 'string', 'max:100'],
            'role'       => ['nullable', 'string', 'exists:roles,name'],
            'department' => ['nullable', 'string', 'max:100'],
            'is_active'  => ['nullable', 'boolean'],
            'per_page'   => ['nullable', 'integer', 'min:5', 'max:100'],
            'page'       => ['nullable', 'integer', 'min:1'],
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
