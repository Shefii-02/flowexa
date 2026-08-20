<?php

namespace App\Modules\Flow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FlowBuilderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // protected function prepareForValidation(): void
    // {
    //     // Handle trigger_keywords sent as "[]"
    //     if (is_string($this->trigger_keywords)) {
    //         $this->merge([
    //             'trigger_keywords' => json_decode($this->trigger_keywords, true) ?? []
    //         ]);
    //     }
    //     else{
    //         $this->merge([
    //             'trigger_keywords' => []
    //         ]);
    //     }
    // }
    protected function prepareForValidation(): void
    {
        if (is_string($this->trigger_keywords)) {
            $decoded = json_decode($this->trigger_keywords, true);

            $this->merge([
                'trigger_keywords' => is_array($decoded) ? $decoded : [],
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
            ],

            'description' => [
                'nullable',
                'string',
                'max:255',
            ],

            'trigger_type' => [
                'required',
                Rule::in([
                    'default',
                    'keyword',
                    'season'
                ]),
            ],

            'trigger_keywords' => [
                'nullable',
                'array',
            ],

            'trigger_keywords.*' => [
                'string',
                'max:60',
            ],

            'active_from' => [
                'nullable',
                'date',
                Rule::requiredIf(
                    fn() => $this->trigger_type === 'season'
                ),
            ],

            'active_until' => [
                'nullable',
                'date',
                'after:active_from',
                Rule::requiredIf(
                    fn() => $this->trigger_type === 'season'
                ),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'trigger_keywords.array' => 'Keywords must be an array.',
            'trigger_keywords.*.string' => 'Each keyword must be text.',
            'active_until.after' => 'Active until must be after active from.',
        ];
    }
}
