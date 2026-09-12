<?php

// ─── REQUESTS ─────────────────────────────────────────────────────────────────
namespace App\Modules\Lead\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateLeadRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'contact_id'   => ['required', 'integer', 'exists:contacts,id'],
            'category'     => ['nullable', 'string', 'max:100'],
            'priority'     => ['nullable', 'in:low,medium,high'],
            'notes'        => ['nullable', 'string', 'max:1000'],
            'assigned_to'  => ['nullable', 'integer', 'exists:users,id'],
            // Lead Source (which channel) + Lead Origin (which specific number/account/campaign
            // within it) — for a manually-entered lead the staff member knows this first-hand
            // (e.g. a phone call, a referral, a specific ad they mentioned).
            'source'       => ['nullable', 'string', 'max:30'],
            'origin_label' => ['nullable', 'string', 'max:150'],
        ];
    }
    protected function failedValidation(Validator $v): void {
        throw new HttpResponseException(response()->json(['message' => 'Validation failed', 'errors' => $v->errors()], 422));
    }
}
