<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\CheckoutService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class CheckoutController extends Controller
{
    public function store(Request $request, CheckoutService $checkout)
    {
        return $this->run(
            fn () => $checkout->start($request->user())
        );
    }

    public function pay(
        Request $request,
        string $reference,
        CheckoutService $checkout
    ) {
        $order = $this->owned($request, $reference);

        return $this->run(fn () => $checkout->resume($order));
    }

    public function sync(
        Request $request,
        string $reference,
        CheckoutService $checkout
    ) {
        $order = $this->owned($request, $reference);

        return $this->run(function () use ($order, $checkout) {
            $checkout->sync($order);

            return $order->fresh();
        });
    }

    public function cancel(
        Request $request,
        string $reference,
        CheckoutService $checkout
    ) {
        $order = $this->owned($request, $reference);

        return $this->run(function () use ($order, $checkout) {
            $checkout->cancel($order);

            return $order->fresh();
        });
    }

    private function owned(Request $request, string $reference): Order
    {
        return Order::where('user_id', $request->user()->id)
            ->where('reference', $reference)
            ->firstOrFail();
    }

    private function run(callable $action)
    {
        try {
            return response()->json($action());
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Stripe could not complete this request. Check your orders before retrying. If it persists, check the Laravel log.',
            ], 503);
        }
    }
}