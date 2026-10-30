<?php

namespace App\Services;

use App\Contracts\OtpSender;
use Illuminate\Support\Facades\Log;

/** Development sender: writes the code to the log instead of sending it. */
class LogOtpSender implements OtpSender
{
    public function send(string $phoneE164, string $code): void
    {
        Log::info('OTP code', ['phone' => $phoneE164, 'code' => $code]);
    }
}
