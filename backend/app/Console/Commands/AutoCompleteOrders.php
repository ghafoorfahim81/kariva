<?php

namespace App\Console\Commands;

use App\Actions\Orders\OrderFlow;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** A customer who neither confirms nor disputes within the grace period: the system completes the order. */
class AutoCompleteOrders extends Command
{
    protected $signature = 'orders:auto-complete';

    protected $description = 'Complete orders the provider finished and the customer never answered';

    public function handle(OrderFlow $flow): int
    {
        $before = now()->subDays(config('kariva.orders.auto_complete_days'));

        $ids = DB::table('orders')
            ->join('order_events', fn ($j) => $j->on('order_events.order_id', '=', 'orders.id')->where('order_events.event', 'provider_done'))
            ->where('orders.status', 'provider_done')->where('order_events.at', '<', $before)->pluck('orders.id');

        foreach ($ids as $id) {
            $flow->systemAutoComplete(Order::findOrFail($id));
        }
        $this->info("Completed {$ids->count()} order(s).");

        return self::SUCCESS;
    }
}
