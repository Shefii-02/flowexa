<?php

namespace App\Modules\Lead\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ImportLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt'],
            'country_code_mode'    => ['nullable', 'in:with,without'],
            'default_country_code' => ['nullable', 'digits_between:1,4'],
            'stage'      => ['nullable', 'in:new,contacted,follow_up,enrolled,lost'],
            'priority'   => ['nullable', 'in:low,medium,high,normal'],
            'category'   => ['nullable', 'string', 'max:100'],
            'source'     => ['nullable', 'string', 'max:30'],
            'assign_to'  => ['nullable', 'integer'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422));
    }
}
