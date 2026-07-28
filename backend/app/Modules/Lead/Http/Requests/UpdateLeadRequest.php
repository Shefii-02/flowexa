<?php

// ─── REQUESTS ─────────────────────────────────────────────────────────────────
namespace App\Modules\Lead\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class UpdateLeadRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'stage'          => ['sometimes', 'in:new,contacted,follow_up,enrolled,lost'],
            'priority'       => ['sometimes', 'in:low,medium,high'],
            'category'       => ['nullable', 'string', 'max:100'],
            'notes'          => ['nullable', 'string', 'max:1000'],
            'followed_up_at' => ['nullable', 'date'],
        ];
    }
    protected function failedValidation(Validator $v): void {
        throw new HttpResponseException(response()->json(['message' => 'Validation failed', 'errors' => $v->errors()], 422));
    }
}
