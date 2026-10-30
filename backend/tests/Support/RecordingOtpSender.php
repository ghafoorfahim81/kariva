<?php

namespace Tests\Support;

use App\Contracts\OtpSender;

class RecordingOtpSender implements OtpSender
{
    /** @var array<int, array{phone: string, code: string}> */
    public array $sent = [];

    public function send(string $phoneE164, string $code): void
    {
        $this->sent[] = ['phone' => $phoneE164, 'code' => $code];
    }

    public function lastCode(): string
    {
        return end($this->sent)['code'];
    }
}
