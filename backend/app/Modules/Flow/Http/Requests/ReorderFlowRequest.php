<?php

namespace App\Modules\Flow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

// ─── Reorder ──────────────────────────────────────────────────────────────────
class ReorderFlowRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'items'   => ['required', 'array', 'min:1'],
            'items.*' => ['integer', 'exists:flow_nodes,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'A list of node IDs is required.',
            'items.*.exists' => 'One or more node IDs are invalid.',
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
