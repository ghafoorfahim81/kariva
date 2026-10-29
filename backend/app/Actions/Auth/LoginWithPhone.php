<?php

namespace App\Actions\Auth;

use App\Exceptions\ApiException;
use App\Models\Actor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LoginWithPhone
{
    public function __construct(private VerifyOtp $verify) {}

    /** Verifies the code, then returns the user, creating the account and its personal actor on first login. */
    public function __invoke(string $phone, string $code): User
    {
        ($this->verify)($phone, 'login', $code);

        return DB::transaction(function () use ($phone) {
            $user = User::withTrashed()->where('phone_e164', $phone)->lockForUpdate()->first();

            if ($user?->trashed() || $user?->status === 'suspended') {
                throw new ApiException('auth.account_unavailable', 403);
            }

            if ($user === null) {
                $user = User::create(['phone_e164' => $phone]);
                $this->createPersonalActor($user);
            } elseif ($user->status === 'invited') {
                $user->update(['status' => 'active']);
            }

            $user->update(['last_login_at' => now()]);

            return $user;
        });
    }

    private function createPersonalActor(User $user): void
    {
        $actor = Actor::create([
            'type' => 'person',
            'personal_user_id' => $user->id,
            // Name comes later (step 1 of 3 on the profile); until then a neutral placeholder.
            'display_name' => 'کاربر '.substr($user->phone_e164, -4),
            'search_text' => '',
        ]);
        DB::table('actor_members')->insert(['actor_id' => $actor->id, 'user_id' => $user->id, 'role' => 'owner', 'is_primary' => true]);
    }
}
