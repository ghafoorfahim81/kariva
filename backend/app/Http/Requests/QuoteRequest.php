<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuoteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['amount' => Money::clean($this->input('amount'))]);
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            'currency' => ['required', Rule::in(config('kariva.currencies'))],
            'price_unit' => ['required', Rule::in(['hour', 'day', 'meter', 'project', 'piece'])],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
