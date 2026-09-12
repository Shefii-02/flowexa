<?php

namespace App\Modules\Lead\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class BulkDeleteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'lead_ids'   => ['required', 'array', 'min:1', 'max:500'],
            'lead_ids.*' => ['integer'],
        ];
    }

    protected function failedValidation(Validator $v): void
    {
        throw new HttpResponseException(response()->json(['message' => 'Validation failed', 'errors' => $v->errors()], 422));
    }
}
