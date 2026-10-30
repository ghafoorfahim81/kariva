<?php

namespace App\Services;

use App\Contracts\OtpSender;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sends the code through any HTTP gateway (a WhatsApp Business provider or an SMS gateway).
 * The URL, the token and the JSON body come from the environment, so changing the provider needs no code:
 *   OTP_HTTP_BODY='{"to":"{phone}","body":"{message}"}'
 * {phone} is E.164 (+93...), {phone_local} is 07..., {code} and {message} are filled in.
 */
class HttpOtpSender implements OtpSender
{
    public function send(string $phoneE164, string $code): void
    {
        $cfg = config('kariva.otp.http');
        $message = str_replace('{code}', $code, $cfg['message']);

        $body = json_decode(strtr($cfg['body'], [
            '{phone}' => $this->escape($phoneE164),
            '{phone_local}' => $this->escape('0'.substr($phoneE164, 3)),
            '{code}' => $this->escape($code),
            '{message}' => $this->escape($message),
        ]), true);
        if (! is_array($body)) {
            throw new RuntimeException('OTP_HTTP_BODY is not valid JSON.');
        }

        $request = Http::timeout(10)->retry(2, 300, throw: false)->acceptJson();
        if ($cfg['token']) {
            $request = $request->withToken($cfg['token']);
        }

        $response = $request->post($cfg['url'], $body);
        if ($response->failed()) {
            // The code is never put in the log; the status is enough to debug the gateway.
            throw new RuntimeException('OTP gateway answered '.$response->status());
        }
    }

    /** Safe inside a JSON string. */
    private function escape(string $value): string
    {
        return substr(json_encode($value, JSON_UNESCAPED_UNICODE), 1, -1);
    }
}
