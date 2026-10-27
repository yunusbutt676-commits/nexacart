<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\CheckoutService;
use Illuminate\Console\Command;
use Throwable;

class SyncStripeOrders extends Command
{
    protected $signature = 'orders:sync';

    protected $description = 'Reconcile pending orders with Stripe';

    public function handle(CheckoutService $checkout): int
    {
        Order::where('status', 'pending')
            ->where('created_at', '<=', now()->subMinute())
            ->chunkById(100, function ($orders) use ($checkout) {
                foreach ($orders as $order) {
                    try {
                        $checkout->sync($order);
                        $this->info('Checked '.$order->reference);
                    } catch (Throwable $exception) {
                        report($exception);
                        $this->warn('Needs review: '.$order->reference);
                    }
                }
            });

        return self::SUCCESS;
    }
}