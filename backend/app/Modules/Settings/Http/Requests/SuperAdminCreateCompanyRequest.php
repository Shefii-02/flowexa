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
            'owner_phone'     => ['nullable','string','max:20'],
            'company_phone'   => ['nullable','string','max:20'],
            'company_email'   => ['nullable','email'],
            'website'         => ['nullable','string','max:150'],
            'owner_password'  => ['required','string','min:8'],
            'plan_id'         => ['required','integer','exists:plans,id'],
            'initial_balance' => ['nullable','integer','min:0'],
            'business_type'   => ['nullable', \Illuminate\Validation\Rule::in(array_keys(config('industry_templates', [])))],
            'status'          => ['nullable', \Illuminate\Validation\Rule::in(['active','trial'])],
            'trial_days'      => ['nullable','integer','min:1','max:365'],
            'max_devices_per_user' => ['nullable','integer','min:1','max:20'],
        ];
    }
    protected function failedValidation(Validator $v): void
    { throw new HttpResponseException(response()->json(['message'=>'Validation failed','errors'=>$v->errors()],422)); }
}
