<?php

namespace App\Modules\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;



class WalletSettingsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'low_balance_alert'       => ['sometimes', 'integer', 'min:50', 'max:10000'],
            'auto_recharge'           => ['sometimes', 'boolean'],
            'auto_recharge_amount'    => ['sometimes', 'integer', 'in:1000,5000,10000,25000,50000'],
            'auto_recharge_threshold' => ['sometimes', 'integer', 'min:10', 'max:5000'],
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
