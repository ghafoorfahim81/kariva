<?php

namespace App\Console\Commands;

use App\Actions\Orders\OrderFlow;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Orders nobody answers expire: no reply from the provider, or the customer never accepted the quote. */
class ExpireOrders extends Command
{
    protected $signature = 'orders:expire';

    protected $description = 'Expire requested and quoted orders that waited too long';

    public function handle(OrderFlow $flow): int
    {
        $requestedBefore = now()->subHours(config('kariva.orders.expire_requested_hours'));
        $quotedBefore = now()->subDays(config('kariva.orders.expire_quoted_days'));

        $ids = DB::table('orders')->where('status', 'requested')->where('created_at', '<', $requestedBefore)->pluck('id')
            ->merge(
                DB::table('orders')->join('quotes', fn ($j) => $j->on('quotes.order_id', '=', 'orders.id')->where('quotes.status', 'pending'))
                    ->where('orders.status', 'quoted')->where('quotes.created_at', '<', $quotedBefore)->pluck('orders.id')
            );

        foreach ($ids as $id) {
            $flow->systemExpire(Order::findOrFail($id));
        }
        $this->info("Expired {$ids->count()} order(s).");

        return self::SUCCESS;
    }
}
