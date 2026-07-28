<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:100'],
            'name'         => ['required', 'string', 'max:100'],
            'email'        => ['required', 'email', 'unique:users,email'],
            'password'     => ['required', 'string', 'min:8', 'confirmed'],
            'phone'        => ['nullable', 'string', 'max:15'],
        ];
    }

    public function messages(): array
    {
        return [
            'company_name.required' => 'Company name is required.',
            'name.required'         => 'Your name is required.',
            'email.required'        => 'Email address is required.',
            'email.unique'          => 'This email is already registered.',
            'password.min'          => 'Password must be at least 8 characters.',
            'password.confirmed'    => 'Passwords do not match.',
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

// ─────────────────────────────────────────────────────────────────────────────

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'              => ['sometimes', 'string', 'max:100'],
            'email'             => ['sometimes', 'email', 'max:150'],
            'phone'             => ['nullable', 'string', 'max:20'],
            'website'           => ['nullable', 'url', 'max:200'],
            'settings'          => ['nullable', 'array'],
            'settings.timezone' => ['sometimes', 'timezone'],
            'settings.language' => ['sometimes', 'string', 'max:10'],
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

// ─────────────────────────────────────────────────────────────────────────────

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class WaCredentialsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'wa_phone_id'     => ['required', 'string'],
            'wa_access_token' => ['required', 'string'],
            'wa_business_id'  => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'wa_phone_id.required'     => 'WhatsApp Phone Number ID is required.',
            'wa_access_token.required' => 'WhatsApp Access Token is required.',
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
