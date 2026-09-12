<?php

namespace App\Modules\Lead\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class BulkReassignRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'from_user_id'   => ['required', 'integer', 'different:to_user_id'],
            'to_user_id'     => ['required', 'integer'],
            'include_closed' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['from_user_id.different' => 'Pick two different employees.'];
    }

    protected function failedValidation(Validator $v): void
    {
        throw new HttpResponseException(response()->json(['message' => 'Validation failed', 'errors' => $v->errors()], 422));
    }
}
