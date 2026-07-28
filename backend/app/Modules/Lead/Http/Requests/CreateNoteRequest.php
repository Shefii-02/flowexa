<?php

// ─── REQUESTS ─────────────────────────────────────────────────────────────────
namespace App\Modules\Lead\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class CreateNoteRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array {
        return ['content' => ['required', 'string', 'max:2000']];
    }
    protected function failedValidation(Validator $v): void {
        throw new HttpResponseException(response()->json(['message' => 'Validation failed', 'errors' => $v->errors()], 422));
    }
}
