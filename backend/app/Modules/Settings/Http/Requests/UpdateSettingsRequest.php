<?php

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'name'                     => ['sometimes','string','max:100'],
            'email'                    => ['sometimes','email','max:150'],
            'phone'                    => ['nullable','string','max:20'],
            'website'                  => ['nullable','url','max:200'],
            'settings'                 => ['nullable','array'],
            'settings.timezone'        => ['sometimes','timezone'],
            'settings.language'        => ['sometimes','string','max:10'],
            'settings.otp_template'    => ['sometimes','string','max:100'],
            'settings.otp_language'    => ['sometimes','string','max:10'],
        ];
    }
    protected function failedValidation(Validator $v): void
    { throw new HttpResponseException(response()->json(['message'=>'Validation failed','errors'=>$v->errors()],422)); }
}



