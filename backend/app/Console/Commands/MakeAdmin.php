<?php

namespace App\Console\Commands;

use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Admins are made on the server, never through the API: `php artisan kariva:make-admin 0771234567`. */
class MakeAdmin extends Command
{
    protected $signature = 'kariva:make-admin {phone} {--revoke : Remove the admin right instead}';

    protected $description = 'Give or take away platform admin rights (the user must have logged in once)';

    public function handle(): int
    {
        $phone = Phone::normalize($this->argument('phone'));
        if ($phone === null) {
            $this->error('That is not a valid Afghan mobile number.');

            return self::FAILURE;
        }

        $updated = DB::table('users')->where('phone_e164', $phone)->whereNull('deleted_at')->update(['is_platform_admin' => ! $this->option('revoke')]);
        if (! $updated) {
            $this->error("No user with $phone. They must log in once first.");

            return self::FAILURE;
        }
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'action' => $this->option('revoke') ? 'admin.revoked' : 'admin.granted', 'subject_type' => 'user', 'meta' => json_encode(['phone_last4' => substr($phone, -4), 'via' => 'cli'])]);
        $this->info(($this->option('revoke') ? 'Removed admin rights from ' : 'Admin rights granted to ').$phone);

        return self::SUCCESS;
    }
}
