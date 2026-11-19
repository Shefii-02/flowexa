<?php

namespace App\Modules\Lead\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class BulkStatusRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'lead_ids'   => ['required', 'array', 'min:1', 'max:500'],
            'lead_ids.*' => ['integer'],
            'stage'      => ['required_without:priority', 'nullable', 'in:new,contacted,follow_up,enrolled,lost'],
            'priority'   => ['required_without:stage', 'nullable', 'in:low,medium,high'],
        ];
    }

    protected function failedValidation(Validator $v): void
    {
        throw new HttpResponseException(response()->json(['message' => 'Validation failed', 'errors' => $v->errors()], 422));
    }
}
