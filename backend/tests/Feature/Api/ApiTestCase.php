<?php

namespace Tests\Feature\Api;

use App\Contracts\OtpSender;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Fixtures;
use Tests\Support\RecordingOtpSender;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use Fixtures, RefreshDatabase;

    protected RecordingOtpSender $otp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->otp = new RecordingOtpSender;
        $this->app->instance(OtpSender::class, $this->otp);
        RateLimiter::clear('otp');
    }

    /** Fixture user acting through Sanctum, optionally as one of their actors. */
    protected function actingAsUser(string $userId, ?string $actorId = null): static
    {
        $this->actingAs(User::findOrFail($userId), 'sanctum');
        if ($actorId) {
            $this->withHeader('X-Actor-Id', $actorId);
        }

        return $this;
    }
}
