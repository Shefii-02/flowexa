<?php

namespace App\Modules\Contact\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


// ─── Import ───────────────────────────────────────────────────────────────────
class ImportContactRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'file'             => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
            'label_ids'        => ['nullable', 'array'],
            'label_ids.*'      => ['integer', 'exists:contact_labels,id'],
            'skip_duplicates'  => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'Only CSV files are accepted.',
            'file.max'   => 'File size cannot exceed 20MB.',
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
