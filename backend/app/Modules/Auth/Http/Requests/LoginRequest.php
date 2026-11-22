<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
            // the phone app registers itself on login (see App\Support\MobileDevices) — optional so older builds and the web keep working
            'device_uid'  => ['sometimes', 'nullable', 'string', 'max:128'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'platform'    => ['sometimes', 'nullable', 'string', 'max:10'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:20'],
            'push_token'  => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required'    => 'Email address is required.',
            'email.email'       => 'Please provide a valid email address.',
            'password.required' => 'Password is required.',
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
