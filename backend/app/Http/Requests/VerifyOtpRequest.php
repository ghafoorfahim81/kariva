<?php

namespace App\Http\Requests;

use App\Support\Text;

class VerifyOtpRequest extends PhoneRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $code = $this->input('code');
        $this->merge(['code' => is_string($code) ? preg_replace('/\s+/', '', Text::latinDigits($code)) : null]);
    }

    public function rules(): array
    {
        return parent::rules() + ['code' => ['required', 'digits:'.config('kariva.otp.digits')]];
    }
}
