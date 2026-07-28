<?php

// ─── REQUESTS ─────────────────────────────────────────────────────────────────
namespace App\Modules\Lead\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateLeadRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'contact_id'  => ['required', 'integer', 'exists:contacts,id'],
            'category'    => ['nullable', 'string', 'max:100'],
            'priority'    => ['nullable', 'in:low,medium,high'],
            'notes'       => ['nullable', 'string', 'max:1000'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
    protected function failedValidation(Validator $v): void {
        throw new HttpResponseException(response()->json(['message' => 'Validation failed', 'errors' => $v->errors()], 422));
    }
}
