<?php

namespace App\Actions\Auth;

use App\Contracts\OtpSender;
use App\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class SendOtp
{
    public function __construct(private OtpSender $sender) {}

    public function __invoke(string $phone, string $purpose, ?string $ip): void
    {
        $this->throttle($phone, $ip);

        $code = $this->newCode();
        DB::table('otp_codes')->insert([
            'id' => (string) Str::uuid(),
            'phone_e164' => $phone,
            'purpose' => $purpose,
            'code_hash' => self::hash($phone, $code),
            'created_at' => now(),
            'expires_at' => now()->addMinutes(config('kariva.otp.ttl_minutes')),
            'ip_address' => $ip,
        ]);

        $this->sender->send($phone, $code);
    }

    public static function hash(string $phone, string $code): string
    {
        return hash_hmac('sha256', $phone.'|'.$code, config('app.key'));
    }

    private function throttle(string $phone, ?string $ip): void
    {
        $limits = ['otp:phone:'.$phone => config('kariva.otp.max_sends_per_hour')];
        if ($ip !== null) {
            $limits['otp:ip:'.$ip] = config('kariva.otp.max_sends_per_hour_per_ip');
        }

        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new ApiException('auth.otp_rate_limited', 429, ['retry_after' => RateLimiter::availableIn($key)]);
            }
        }
        foreach (array_keys($limits) as $key) {
            RateLimiter::hit($key, 3600);
        }
    }

    private function newCode(): string
    {
        $fake = config('kariva.otp.fake_code');
        if ($fake !== null && ! app()->isProduction()) {
            return (string) $fake;
        }

        return str_pad((string) random_int(0, 10 ** config('kariva.otp.digits') - 1), config('kariva.otp.digits'), '0', STR_PAD_LEFT);
    }
}
