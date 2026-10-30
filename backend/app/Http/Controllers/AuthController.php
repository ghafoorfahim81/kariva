<?php

namespace App\Http\Controllers;

use App\Actions\Auth\LoginWithPhone;
use App\Actions\Auth\SendOtp;
use App\Http\Requests\PhoneRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /** Always 204: the answer must not reveal whether the number is registered. */
    public function sendOtp(PhoneRequest $request, SendOtp $send): JsonResponse
    {
        $send($request->validated('phone'), 'login', $request->ip());

        return response()->json(null, 204);
    }

    /** Browser SPA gets a session cookie; any client without a session (the future mobile app) gets a token. */
    public function verify(VerifyOtpRequest $request, LoginWithPhone $login): JsonResponse
    {
        $user = $login($request->validated('phone'), $request->validated('code'));
        $payload = [];

        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        } else {
            $payload['token'] = $user->createToken($request->input('device_name', 'mobile'))->plainTextToken;
        }

        return response()->json(['user' => new UserResource($user->load('actors'))] + $payload);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('actors'));
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(null, 204);
    }
}
