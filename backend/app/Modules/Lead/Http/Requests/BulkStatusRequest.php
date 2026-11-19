<?php

namespace App\Modules\Lead\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class BulkStatusRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'lead_ids'   => ['required', 'array', 'min:1', 'max:500'],
            'lead_ids.*' => ['integer'],
            // at least one change must be asked for: stage, priority, categories (add / replace / remove them) or clear all categories
            'stage'      => ['required_without_all:priority,category,categories,clear_category', 'nullable', 'in:new,contacted,follow_up,enrolled,lost'],
            'priority'   => ['required_without_all:stage,category,categories,clear_category', 'nullable', 'in:low,medium,high'],
            // existing lead categories of this company (leads store the NAME, so a typo would create a ghost category)
            'categories'   => ['required_without_all:stage,priority,category,clear_category', 'nullable', 'array', 'min:1', 'max:30'],
            'categories.*' => ['string', 'max:120', Rule::exists('lead_categories', 'name')->where('company_id', auth()->user()?->company_id)],
            'category_mode' => ['nullable', 'in:add,replace,remove'],     // default: add
            // older single-category form: sets that one category (replacing) / clears every category
            'category'   => ['nullable', 'string', 'max:120', Rule::exists('lead_categories', 'name')->where('company_id', auth()->user()?->company_id)],
            'clear_category' => ['nullable', 'boolean'],
        ];
    }

    protected function failedValidation(Validator $v): void
    {
        throw new HttpResponseException(response()->json(['message' => 'Validation failed', 'errors' => $v->errors()], 422));
    }
}
