<?php

namespace App\Modules\Flow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

// ─── Create Flow Node ─────────────────────────────────────────────────────────
class CreateFlowNodeRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'title'         => ['required', 'string', 'max:24'],
            'message'       => ['nullable', 'string', 'max:1024'],
            'type'          => ['required', 'in:list,button,text,survey,template'],
            'parent_id'     => ['nullable', 'integer', 'exists:flow_nodes,id'],
            'reply_id'      => ['nullable', 'string', 'max:50', 'alpha_dash'],
            'lead_category' => ['nullable', 'string', 'max:100'],
            'is_active'     => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.max'    => 'Title must be 24 characters or less (WhatsApp button limit).',
            'message.max'  => 'Message must be 1024 characters or less.',
            'type.in'      => 'Type must be one of: list, button, text.',
            'reply_id.alpha_dash' => 'Reply ID may only contain letters, numbers, dashes and underscores.',
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
