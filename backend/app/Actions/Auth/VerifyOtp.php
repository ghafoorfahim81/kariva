<?php

namespace App\Actions\Auth;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

class VerifyOtp
{
    /** Checks a code for the phone. Counts the attempt, burns the code on success. Wrong, expired and unknown all look the same. */
    public function __invoke(string $phone, string $purpose, string $code): void
    {
        $accepted = DB::transaction(function () use ($phone, $purpose, $code): bool {
            $otp = DB::table('otp_codes')
                ->where(['phone_e164' => $phone, 'purpose' => $purpose])
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->where('attempts', '<', config('kariva.otp.max_attempts'))
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if (! $otp) {
                return false;
            }

            if (! hash_equals($otp->code_hash, SendOtp::hash($phone, $code))) {
                DB::table('otp_codes')->where('id', $otp->id)->increment('attempts');

                return false;
            }

            DB::table('otp_codes')->where('id', $otp->id)->update(['consumed_at' => now()]);

            return true;
        });

        if (! $accepted) {
            throw new ApiException('auth.otp_invalid', 422);
        }
    }
}
