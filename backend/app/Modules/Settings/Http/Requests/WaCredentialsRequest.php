<?php
namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class WaCredentialsRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'wa_phone_id'     => ['required','string'],
            'wa_access_token' => ['required','string'],
            'wa_business_id'  => ['nullable','string'],
        ];
    }
    protected function failedValidation(Validator $v): void
    { throw new HttpResponseException(response()->json(['message'=>'Validation failed','errors'=>$v->errors()],422)); }
}
