<?php

namespace App\Console\Commands;

use App\Support\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireNeeds extends Command
{
    protected $signature = 'needs:expire';

    protected $description = 'Close needs whose time is over and turn down their pending offers';

    public function handle(): int
    {
        $needs = DB::table('needs')->where('status', 'open')->whereNotNull('expires_at')->where('expires_at', '<', now())->get(['id', 'actor_id', 'title']);

        foreach ($needs as $need) {
            DB::transaction(function () use ($need) {
                $offers = DB::table('offers')->where(['need_id' => $need->id, 'status' => 'pending']);
                foreach ((clone $offers)->get(['id', 'provider_actor_id']) as $o) {
                    Notifier::actor($o->provider_actor_id, 'offer.rejected', "offer:{$o->id}:rejected", ['need_id' => $need->id, 'title' => $need->title]);
                }
                $offers->update(['status' => 'rejected', 'updated_at' => now()]);
                DB::table('needs')->where('id', $need->id)->update(['status' => 'expired', 'updated_at' => now()]);
                Notifier::actor($need->actor_id, 'need.expired', "need:{$need->id}:expired", ['need_id' => $need->id, 'title' => $need->title]);
            });
        }
        $this->info("Expired {$needs->count()} need(s).");

        return self::SUCCESS;
    }
}
