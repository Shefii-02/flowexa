<?php

namespace App\Modules\Campaign\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                => ['sometimes', 'string', 'max:150'],
            'description'         => ['nullable', 'string', 'max:500'],
            'template_variables'  => ['nullable', 'array'],
            'throttle_per_minute' => ['sometimes', 'integer', 'min:10', 'max:1000'],
            'scheduled_at'        => ['nullable', 'date', 'after:now'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422));
    }
}
