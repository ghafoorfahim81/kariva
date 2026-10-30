<?php

namespace App\Contracts;

interface OtpSender
{
    /** Delivers the plain code to the phone (E.164). SMS is unreliable in Afghanistan, so WhatsApp may replace this later. */
    public function send(string $phoneE164, string $code): void;
}
