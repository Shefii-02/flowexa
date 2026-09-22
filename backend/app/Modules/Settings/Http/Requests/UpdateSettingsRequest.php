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
            'settings.crm_auto_save_wa_chat'  => ['sometimes','boolean'],
            'settings.crm_auto_save_wa_cloud' => ['sometimes','boolean'],

            'settings.response_mode_wa_chat'  => ['sometimes','in:manual,ai_agent'],
            'settings.response_mode_wa_cloud' => ['sometimes','in:manual,ai_agent,chat_bot'],

            'settings.response_schedule_wa_chat'           => ['sometimes','array'],
            'settings.response_schedule_wa_chat.mode'      => ['sometimes','in:always,scheduled'],
            'settings.response_schedule_wa_chat.days'      => ['sometimes','array'],
            'settings.response_schedule_wa_chat.days.*'    => ['integer','min:0','max:6'],
            'settings.response_schedule_wa_chat.start'     => ['sometimes','nullable','string','max:8'],
            'settings.response_schedule_wa_chat.end'       => ['sometimes','nullable','string','max:8'],
            'settings.response_schedule_wa_chat.timezone'  => ['sometimes','nullable','string','max:64'],

            'settings.response_schedule_wa_cloud'          => ['sometimes','array'],
            'settings.response_schedule_wa_cloud.mode'     => ['sometimes','in:always,scheduled'],
            'settings.response_schedule_wa_cloud.days'     => ['sometimes','array'],
            'settings.response_schedule_wa_cloud.days.*'   => ['integer','min:0','max:6'],
            'settings.response_schedule_wa_cloud.start'    => ['sometimes','nullable','string','max:8'],
            'settings.response_schedule_wa_cloud.end'      => ['sometimes','nullable','string','max:8'],
            'settings.response_schedule_wa_cloud.timezone' => ['sometimes','nullable','string','max:64'],
        ];
    }
    protected function failedValidation(Validator $v): void
    { throw new HttpResponseException(response()->json(['message'=>'Validation failed','errors'=>$v->errors()],422)); }
}



