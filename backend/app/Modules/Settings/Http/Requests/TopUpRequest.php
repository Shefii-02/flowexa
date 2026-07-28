<?php
namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class TopUpRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'amount'      => ['required','integer','min:1','max:1000000'],
            'description' => ['nullable','string','max:200'],
        ];
    }
    protected function failedValidation(Validator $v): void
    { throw new HttpResponseException(response()->json(['message'=>'Validation failed','errors'=>$v->errors()],422)); }
}
