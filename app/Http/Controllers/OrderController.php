<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        return Order::where('user_id', $request->user()->id)
            ->latest('id')
            ->paginate(10);
    }

    public function show(Request $request, string $reference)
    {
        return Order::where('user_id', $request->user()->id)
            ->where('reference', $reference)
            ->firstOrFail();
    }

    public function adminIndex()
    {
        return Order::latest('id')->paginate(10);
    }

    public function update(Request $request, string $reference)
    {
        $data = $request->validate([
            'fulfillment_status' => [
                'required',
                Rule::in(['shipped', 'delivered']),
            ],
        ]);

        return DB::transaction(function () use ($reference, $data) {
            $order = Order::where('reference', $reference)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($order->status === 'paid', 422, 'Order is not paid.');

            $next = match ($order->fulfillment_status) {
                'processing' => 'shipped',
                'shipped' => 'delivered',
                default => null,
            };

            abort_unless(
                $data['fulfillment_status'] === $next,
                422,
                'Invalid fulfillment transition.'
            );

            $order->fulfillment_status = $next;
            $order->save();

            return $order;
        });
    }
}