<?php

namespace App\Http\Requests;

use App\Support\Text;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('price_amount'))) {
            // "۱٬۲۰۰" or "1,200" both become 1200
            $this->merge(['price_amount' => str_replace([',', '٬', ' '], '', Text::latinDigits($this->input('price_amount')))]);
        }
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $priced = Rule::requiredIf(fn () => in_array($this->input('pricing_type'), ['fixed', 'from'], true));

        return [
            'title' => [$required, 'string', 'min:2', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category_id' => [$required, 'uuid', Rule::exists('categories', 'id')->where('is_active', true)],
            'delivery_mode' => [$required, Rule::in(['onsite', 'online', 'both'])],
            'pricing_type' => [$required, Rule::in(['fixed', 'from', 'negotiable'])],
            'price_amount' => [$priced, 'nullable', 'numeric', 'gt:0', 'max:999999999999.99', 'prohibited_if:pricing_type,negotiable'],
            'currency' => [$priced, 'nullable', Rule::in(config('kariva.currencies'))],
            'price_unit' => [$priced, 'nullable', Rule::in(['hour', 'day', 'meter', 'project', 'piece'])],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'paused', 'archived'])],
        ];
    }

    /** A negotiable service carries no price at all. */
    public function attributesForModel(): array
    {
        $data = $this->validated();
        if (($data['pricing_type'] ?? null) === 'negotiable') {
            $data['price_amount'] = $data['currency'] = $data['price_unit'] = null;
        }

        return $data;
    }
}
