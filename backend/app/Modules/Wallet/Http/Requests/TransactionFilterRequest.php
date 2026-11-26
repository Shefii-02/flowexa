<?php

namespace App\Modules\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class TransactionFilterRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'type'     => ['nullable', 'in:credit,debit'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'from'     => ['nullable', 'date'],
            'to'       => ['nullable', 'date'],
            'source'   => ['nullable', 'string', 'max:40'],
            'q'        => ['nullable', 'string', 'max:100'],
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
