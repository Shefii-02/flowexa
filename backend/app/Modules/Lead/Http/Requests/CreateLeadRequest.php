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
            'listing_id'   => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('listings', 'id')->where('company_id', auth()->user()->company_id)],
            'assigned_to'  => ['nullable', 'integer', 'exists:users,id'],
            // Lead Source (which channel) + Lead Origin (which specific number/account/campaign
            // within it) — for a manually-entered lead the staff member knows this first-hand
            // (e.g. a phone call, a referral, a specific ad they mentioned).
            'source'       => ['nullable', 'string', 'max:30'],
            'origin_label' => ['nullable', 'string', 'max:150'],
            // a chosen number / account / campaign (from GET /leads/origins) — its source and label are filled in from it
            'origin_type'  => ['nullable', 'string', 'max:30', 'required_with:origin_id'],
            'origin_id'    => ['nullable', 'integer', 'min:1', 'required_with:origin_type'],
            // several categories at once (the first is the primary); each must already exist
            'categories'   => ['nullable', 'array', 'max:30'],
            'categories.*' => ['string', 'max:120', \Illuminate\Validation\Rule::exists('lead_categories', 'name')->where('company_id', auth()->user()->company_id)],
        ];
    }
    protected function failedValidation(Validator $v): void {
        throw new HttpResponseException(response()->json(['message' => 'Validation failed', 'errors' => $v->errors()], 422));
    }
}
