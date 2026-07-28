<?php

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class SuperAdminCreateCompanyRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'company_name'    => ['required','string','max:100'],
            'owner_name'      => ['required','string','max:100'],
            'owner_email'     => ['required','email','unique:users,email'],
            'owner_password'  => ['required','string','min:8'],
            'plan_id'         => ['required','integer','exists:plans,id'],
            'initial_balance' => ['nullable','integer','min:0'],
        ];
    }
    protected function failedValidation(Validator $v): void
    { throw new HttpResponseException(response()->json(['message'=>'Validation failed','errors'=>$v->errors()],422)); }
}
