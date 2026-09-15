<?php

namespace App\Modules\Staff\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

// ─── Create Staff ─────────────────────────────────────────────────────────────
class CreateStaffRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'       => ['required', 'string', 'max:100'],
            'email'      => ['required', 'email', 'unique:users,email'],
            'password'   => ['required', 'string', 'min:8', 'confirmed'],
            // A plain exists:roles,id let an admin assign ANY company's custom role by id,
            // including a role from an entirely different tenant — the assigned staff member
            // would then get whatever permission set that other company configured for it.
            // Scope to the acting user's own company, or a global system role (company_id null).
            'role_id'    => ['required', 'integer', Rule::exists('roles', 'id')->where(
                fn ($q) => $q->where('company_id', $this->user()?->company_id)->orWhereNull('company_id')
            )],
            'phone'      => ['nullable', 'string', 'max:20'],
            'department' => ['nullable', 'string', 'max:100'],
            'max_leads'  => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique'      => 'A user with this email already exists.',
            'password.min'      => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Passwords do not match.',
            'role_id.exists'    => 'Selected role does not exist.',
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






