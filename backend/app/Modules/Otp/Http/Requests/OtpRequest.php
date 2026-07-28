<?php
namespace App\Modules\Otp\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class SendOtpRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array { return ['phone' => ['required','string','min:7','max:20'], 'device_id' => ['required','string','max:200']]; }
    protected function failedValidation(Validator $v): void { throw new HttpResponseException(response()->json(['message'=>'Validation failed','errors'=>$v->errors()],422)); }
}

class VerifyOtpRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array { return ['ref_id' => ['required','string','uuid'], 'otp' => ['required','string','size:6','numeric'], 'device_id' => ['required','string','max:200']]; }
    protected function failedValidation(Validator $v): void { throw new HttpResponseException(response()->json(['message'=>'Validation failed','errors'=>$v->errors()],422)); }
}
