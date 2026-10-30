<?php

namespace App\Http\Requests;

use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;

/** Normalizes any typed phone to E.164 before validation, so controllers only see clean numbers. */
class PhoneRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $raw = $this->input('phone');
        $this->merge(['phone' => is_string($raw) ? Phone::normalize($raw) : null]);
    }

    public function rules(): array
    {
        return ['phone' => ['required', 'regex:/^\+937\d{8}$/']];
    }
}
