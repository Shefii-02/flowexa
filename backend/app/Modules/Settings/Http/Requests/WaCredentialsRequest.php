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
            'wa_phone_id'     => ['required','string','regex:/^\d{5,20}$/'],
            'wa_access_token' => ['required','string'],
            'wa_business_id'  => ['nullable','string','regex:/^\d{5,20}$/'],
            'meta_app_id'     => ['nullable','string','regex:/^\d{5,20}$/'],
        ];
    }
    public function messages(): array
    {
        return [
            'wa_phone_id.regex'    => 'Phone Number ID must be numeric (5–20 digits), not the display phone number.',
            'meta_app_id.regex'    => 'Meta App ID must be numeric (5–20 digits) — find it in Meta Developer Console → App settings → Basic.',
            'wa_business_id.regex' => 'WABA ID must be numeric (5–20 digits).',
        ];
    }
    protected function failedValidation(Validator $v): void
    { throw new HttpResponseException(response()->json(['message'=>'Validation failed','errors'=>$v->errors()],422)); }
}
