<?php

namespace App\Modules\Flow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


// ─── Update Flow Node ─────────────────────────────────────────────────────────
class UpdateFlowNodeRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'title'         => ['sometimes', 'string', 'max:24'],
            'message'       => ['sometimes', 'string', 'max:1024'],
            'type'          => ['sometimes', 'in:list,button,text,survey,template'],
            'parent_id'     => ['nullable', 'integer', 'exists:flow_nodes,id'],
            'reply_id'      => ['nullable', 'string', 'max:50', 'alpha_dash'],
            'lead_category' => ['nullable', 'string', 'max:100'],
            'is_active'     => ['nullable', 'boolean'],
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

