<?php

namespace App\Modules\Staff\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

// ─── Update Staff ─────────────────────────────────────────────────────────────
class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'       => ['sometimes', 'string', 'max:100'],
            'role_id'    => ['sometimes', 'integer', 'exists:roles,id'],
            'phone'      => ['nullable', 'string', 'max:20'],
            'department' => ['nullable', 'string', 'max:100'],
            'max_leads'  => ['nullable', 'integer', 'min:1', 'max:500'],
            'is_active'  => ['sometimes', 'boolean'],
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
