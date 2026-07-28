<?php

// ─── REQUESTS ─────────────────────────────────────────────────────────────────
namespace App\Modules\Lead\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class LeadFilterRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'stage'       => ['nullable', 'in:new,contacted,follow_up,enrolled,lost'],
            'priority'    => ['nullable', 'in:low,medium,high'],
            'category'    => ['nullable', 'string', 'max:100'],
            'assigned_to' => ['nullable', 'integer'],
            'source'      => ['nullable', 'string'],
            'search'      => ['nullable', 'string', 'max:100'],
            'per_page'    => ['nullable', 'integer', 'min:5', 'max:100'],
            'page'        => ['nullable', 'integer', 'min:1'],
        ];
    }
    protected function failedValidation(Validator $v): void {
        throw new HttpResponseException(response()->json(['message' => 'Validation failed', 'errors' => $v->errors()], 422));
    }
}
