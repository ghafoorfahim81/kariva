<?php

namespace Tests\Feature\Api;

use App\Contracts\OtpSender;
use App\Services\HttpOtpSender;
use App\Services\LogOtpSender;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaunchReadinessTest extends TestCase
{
    private function gateway(array $o = []): void
    {
        config(['kariva.otp.driver' => 'http', 'kariva.otp.http' => $o + [
            'url' => 'https://gateway.test/send', 'token' => 'secret-token',
            'body' => '{"to":"{phone}","message":"{message}"}', 'message' => 'کد ورود کاریوا: {code}',
        ]]);
    }

    // ---------------------------------------------------------------- the OTP gateway

    public function test_the_driver_decides_how_codes_are_sent(): void
    {
        config(['kariva.otp.driver' => 'log']);
        $this->assertInstanceOf(LogOtpSender::class, app(OtpSender::class));

        config(['kariva.otp.driver' => 'http']);
        $this->assertInstanceOf(HttpOtpSender::class, app(OtpSender::class));
    }

    public function test_the_code_is_posted_to_the_gateway_with_the_token(): void
    {
        $this->gateway();
        Http::fake(['gateway.test/*' => Http::response(['ok' => true])]);

        app(OtpSender::class)->send('+93771234567', '482913');

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://gateway.test/send'
            && $r->hasHeader('Authorization', 'Bearer secret-token')
            && $r['to'] === '+93771234567'
            && $r['message'] === 'کد ورود کاریوا: 482913');
    }

    public function test_the_body_template_can_match_any_provider(): void
    {
        $this->gateway(['body' => '{"chatId":"{phone_local}","text":"{message}","code":"{code}"}']);
        Http::fake(['*' => Http::response([])]);

        app(OtpSender::class)->send('+93771234567', '111222');

        Http::assertSent(fn (HttpRequest $r) => $r['chatId'] === '0771234567' && $r['code'] === '111222' && str_contains($r['text'], '111222'));
    }

    public function test_quotes_in_the_message_cannot_break_the_json(): void
    {
        $this->gateway(['message' => 'Kariva "code": {code}\\ok']);
        Http::fake(['*' => Http::response([])]);

        app(OtpSender::class)->send('+93771234567', '123456');

        Http::assertSent(fn (HttpRequest $r) => $r['message'] === 'Kariva "code": 123456\\ok');
    }

    public function test_a_gateway_error_is_an_exception_without_the_code_in_it(): void
    {
        $this->gateway();
        Http::fake(['*' => Http::response('boom', 500)]);

        try {
            app(OtpSender::class)->send('+93771234567', '987654');
            $this->fail('A gateway failure must not pass silently.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('500', $e->getMessage());
            $this->assertStringNotContainsString('987654', $e->getMessage());
        }
    }

    public function test_a_broken_body_template_is_reported(): void
    {
        $this->gateway(['body' => 'not json {phone}']);
        Http::fake(['*' => Http::response([])]);

        $this->expectException(\RuntimeException::class);
        app(OtpSender::class)->send('+93771234567', '123456');
    }

    // ---------------------------------------------------------------- preflight

    private function liveSettings(): void
    {
        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.debug' => false, 'app.env' => 'production', 'app.url' => 'https://kariva.example',
            'kariva.otp.driver' => 'http', 'kariva.otp.fake_code' => null, 'kariva.media.scanner' => 'clamav',
            'queue.default' => 'redis', 'session.secure' => true, 'kariva.media.private_disk' => 'local', 'kariva.media.public_disk' => 's3',
        ]);
    }

    public function test_preflight_passes_with_live_settings(): void
    {
        $this->liveSettings();

        $this->artisan('kariva:preflight', ['--production' => true])->expectsOutputToContain('Preflight passed')->assertSuccessful();
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function unsafeSettings(): array
    {
        return [
            'debug on' => [['app.debug' => true], 'APP_DEBUG'],
            'log otp driver' => [['kariva.otp.driver' => 'log'], 'OTP_DRIVER'],
            'fake otp code' => [['kariva.otp.fake_code' => '123456'], 'OTP_FAKE_CODE'],
            'no virus scanner' => [['kariva.media.scanner' => 'noop'], 'MEDIA_SCANNER'],
            'sync queue' => [['queue.default' => 'sync'], 'QUEUE_CONNECTION'],
            'cookie not secure' => [['session.secure' => false], 'SESSION_SECURE_COOKIE'],
            'plain http url' => [['app.url' => 'http://kariva.example'], 'APP_URL'],
            'one disk for everything' => [['kariva.media.private_disk' => 's3'], 'different disks'],
            'weak otp limit' => [['kariva.otp.max_sends_per_hour' => 50], 'OTP limit'],
            'no key' => [['app.key' => ''], 'APP_KEY'],
        ];
    }

    /** @dataProvider unsafeSettings */
    #[DataProvider('unsafeSettings')]
    public function test_preflight_stops_unsafe_settings(array $change, string $mention): void
    {
        $this->liveSettings();
        config($change);

        $this->artisan('kariva:preflight', ['--production' => true])->expectsOutputToContain($mention)->assertFailed();
    }

    public function test_a_development_machine_fails_preflight_in_production_mode(): void
    {
        $this->artisan('kariva:preflight', ['--production' => true])->assertFailed();
    }
}
