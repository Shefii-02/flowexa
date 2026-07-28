<?php

namespace App\Modules\Campaign\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateCampaignRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'                => ['required', 'string', 'max:150'],
            'description'         => ['nullable', 'string', 'max:500'],
            'template_id'         => ['required', 'integer', 'exists:wa_templates,id'],
            'template_variables'  => ['nullable', 'array'],
            'target_type'         => ['required', 'in:csv,labels,all'],
            'target_labels'       => ['required_if:target_type,labels', 'array'],
            'target_labels.*'     => ['integer', 'exists:contact_labels,id'],
            'file'                => ['required_if:target_type,csv', 'file', 'mimes:csv,txt', 'max:20480'],
            'throttle_per_minute' => ['nullable', 'integer', 'min:10', 'max:1000'],
            'scheduled_at'        => ['nullable', 'date', 'after:now'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422));
    }
}

