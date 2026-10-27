<?php

namespace App\Services;

use App\Models\Address;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\Exception\InvalidRequestException;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

class CheckoutService
{
    private function stripe(): StripeClient
    {
        $key = config('services.stripe.secret');

        abort_unless(
            is_string($key) && str_starts_with($key, 'sk_test_'),
            503,
            'Configure a Stripe sandbox secret key first.'
        );

        $http = new CurlClient();
        $http->setConnectTimeout(5);
        $http->setTimeout(20);

        \Stripe\ApiRequestor::setHttpClient($http);

        return new StripeClient([
            'api_key' => $key,
            'max_network_retries' => 0,
        ]);
    }

    public function start(User $user): array
    {
        // Validate configuration before reserving inventory.
        $this->stripe();

        return Cache::lock('checkout-user-'.$user->id, 120)
            ->block(3, function () use ($user) {
                $order = DB::transaction(function () use ($user) {
                    User::whereKey($user->id)->lockForUpdate()->firstOrFail();

                    $existing = Order::where('user_id', $user->id)
                        ->where('status', 'pending')
                        ->latest('id')
                        ->first();

                    if ($existing) {
                        return $existing;
                    }

                    $address = Address::where('user_id', $user->id)->first();

                    abort_unless($address, 422, 'Save your shipping address first.');

                    $cart = CartItem::where('user_id', $user->id)
                        ->orderBy('product_id')
                        ->get();

                    abort_if($cart->isEmpty(), 422, 'Your cart is empty.');

                    $products = Product::whereIn('id', $cart->pluck('product_id'))
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    $items = [];
                    $total = 0;

                    foreach ($cart as $line) {
                        $product = $products->get($line->product_id);

                        abort_unless(
                            $product && $product->is_active,
                            422,
                            'A product in your cart is no longer available.'
                        );

                        abort_if(
                            $line->quantity < 1 ||
                            $line->quantity > min(99, $product->stock),
                            422,
                            "Insufficient stock for {$product->name}."
                        );

                        $items[] = [
                            'product_id' => $product->id,
                            'name' => $product->name,
                            'unit_price' => $product->price,
                            'quantity' => $line->quantity,
                            'image_url' => $product->image_urls[0] ?? null,
                            'cart_id' => $line->id,
                            'cart_updated_at' => $line->updated_at->format('Y-m-d H:i:s'),
                        ];

                        $total += $product->price * $line->quantity;

                        $product->stock -= $line->quantity;
                        $product->reserved_stock += $line->quantity;
                        $product->save();
                    }

                    // Application limits for this PKR sandbox store.
                    abort_if(
                        $total < 20000 || $total > 99999999,
                        422,
                        'Checkout total must be between PKR 200 and PKR 999,999.99.'
                    );

                    $reference = (string) Str::uuid();
                    $frontend = rtrim(config('frontend.url'), '/');
                    $expires = now()->addMinutes(45);

                    $order = new Order();
                    $order->reference = $reference;
                    $order->user_id = $user->id;
                    $order->email = $user->email;
                    $order->status = 'pending';
                    $order->fulfillment_status = 'unfulfilled';
                    $order->currency = 'pkr';
                    $order->total = $total;
                    $order->items = $items;
                    $order->expires_at = $expires;

                    $order->shipping_address = $address->only([
                        'name',
                        'phone',
                        'line1',
                        'line2',
                        'city',
                        'state',
                        'postal_code',
                        'country_code',
                    ]);

                    // Persist an immutable payload for safe idempotent retries.
                    $order->stripe_payload = [
                        'mode' => 'payment',
                        'customer_email' => $user->email,
                        'client_reference_id' => $reference,
                        'metadata' => [
                            'app' => 'nexacart',
                            'order_reference' => $reference,
                        ],
                        'line_items' => array_map(fn ($item) => [
                            'price_data' => [
                                'currency' => 'pkr',
                                'unit_amount' => $item['unit_price'],
                                'product_data' => [
                                    'name' => $item['name'],
                                ],
                            ],
                            'quantity' => $item['quantity'],
                        ], $items),
                        'expires_at' => $expires->timestamp,
                        'success_url' => $frontend.'/orders/'.$reference,
                        'cancel_url' => $frontend.'/orders/'.$reference,
                    ];

                    $order->save();

                    return $order;
                });

                return $this->checkoutUrl($order);
            });
    }

    public function resume(Order $order): array
    {
        return Cache::lock('checkout-user-'.$order->user_id, 120)
            ->block(3, fn () => $this->checkoutUrl($order->fresh()));
    }

    private function checkoutUrl(Order $order): array
    {
        if ($order->status !== 'pending') {
            return [
                'url' => config('frontend.url').'/orders/'.$order->reference,
            ];
        }

        $session = $this->resolveSession($order, true);
        $this->apply($session);

        $order->refresh();

        return [
            'url' => $order->status === 'pending' && $session->status === 'open'
                ? $session->url
                : config('frontend.url').'/orders/'.$order->reference,
        ];
    }

    private function resolveSession(Order $order, bool $allowCreate): Session
    {
        $stripe = $this->stripe();

        if ($order->stripe_session_id) {
            return $stripe->checkout->sessions->retrieve(
                $order->stripe_session_id
            );
        }

        if ($allowCreate && $order->created_at->gt(now()->subMinutes(10))) {
            try {
                $session = $stripe->checkout->sessions->create(
                    $order->stripe_payload,
                    ['idempotency_key' => 'nexacart-'.$order->reference]
                );

                $this->apply($session);

                return $session;
            } catch (InvalidRequestException $exception) {
                // A definite rejected request did not create a payable session.
                // Ambiguous network/API failures are not released here.
                if ($exception->getHttpStatus() === 400) {
                    DB::transaction(function () use ($order) {
                        $locked = Order::whereKey($order->id)
                            ->lockForUpdate()
                            ->firstOrFail();

                        if (! $locked->stripe_session_id) {
                            $this->finish($locked, 'failed');
                        }
                    });
                }

                throw $exception;
            }
        }

        // Recover a session if Stripe succeeded but the local response was lost.
        $sessions = $stripe->checkout->sessions->all([
            'created' => [
                'gte' => $order->created_at->timestamp - 60,
                'lte' => $order->created_at->timestamp + 660,
            ],
            'limit' => 100,
        ]);

        foreach ($sessions->autoPagingIterator() as $session) {
            if (($session->metadata->order_reference ?? null) === $order->reference) {
                $this->apply($session);

                return $session;
            }
        }

        // Do not release stock while Stripe's outcome is unknown.
        throw new RuntimeException(
            'Unresolved Stripe session for order '.$order->reference
        );
    }

    public function sync(Order $order): void
    {
        if ($order->status !== 'pending') {
            return;
        }

        Cache::lock('checkout-user-'.$order->user_id, 120)
            ->block(3, function () use ($order) {
                $order->refresh();

                if ($order->status !== 'pending') {
                    return;
                }

                $session = $this->resolveSession($order, false);
                $this->apply($session);
            });
    }

    public function cancel(Order $order): void
    {
        Cache::lock('checkout-user-'.$order->user_id, 120)
            ->block(3, function () use ($order) {
                $order->refresh();

                if ($order->status !== 'pending') {
                    return;
                }

                // Resolve/create the idempotent session before cancelling it.
                $session = $this->resolveSession($order, true);

                if ($session->status === 'open') {
                    try {
                        $session = $this->stripe()->checkout->sessions->expire(
                            $session->id
                        );
                    } catch (InvalidRequestException $exception) {
                        // Payment may have completed concurrently.
                        $session = $this->stripe()->checkout->sessions->retrieve(
                            $session->id
                        );
                    }
                }

                $this->apply($session);

                abort_if(
                    $order->fresh()->status === 'pending',
                    409,
                    'Stripe is still processing this payment. Check again shortly.'
                );
            });
    }

    public function apply(Session $session): void
    {
        $reference = $session->metadata->order_reference ?? null;

        if (! $reference || ($session->metadata->app ?? null) !== 'nexacart') {
            return;
        }

        DB::transaction(function () use ($session, $reference) {
            $order = Order::where('reference', $reference)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                return;
            }

            if (
                $session->livemode ||
                $session->mode !== 'payment' ||
                $session->client_reference_id !== $order->reference ||
                strtolower((string) $session->currency) !== $order->currency ||
                (int) $session->amount_total !== $order->total ||
                ($order->stripe_session_id &&
                    $order->stripe_session_id !== $session->id)
            ) {
                throw new RuntimeException('Stripe order verification failed.');
            }

            $order->stripe_session_id = $session->id;
            $order->checkout_url = $session->url;
            $order->save();

            if ($session->payment_status === 'paid') {
                if (in_array($order->status, ['expired', 'failed'], true)) {
                    throw new RuntimeException(
                        'Payment received for a released order: '.$order->reference
                    );
                }

                $this->finish($order, 'paid');
            } elseif ($session->status === 'expired') {
                $this->finish($order, 'expired');
            }
        });
    }

    private function finish(Order $order, string $status): void
    {
        // Caller holds the order row lock.
        if ($order->status !== 'pending') {
            return;
        }

        $products = Product::withTrashed()
            ->whereIn('id', array_column($order->items, 'product_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($order->items as $item) {
            $product = $products->get($item['product_id']);

            if (! $product || $product->reserved_stock < $item['quantity']) {
                throw new RuntimeException('Inventory reservation is inconsistent.');
            }

            $product->reserved_stock -= $item['quantity'];

            if ($status !== 'paid') {
                $product->stock += $item['quantity'];
            }

            $product->save();

            if ($status === 'paid') {
                // Preserve cart lines changed after this checkout started.
                CartItem::where('user_id', $order->user_id)
                    ->whereKey($item['cart_id'])
                    ->where('quantity', $item['quantity'])
                    ->where('updated_at', $item['cart_updated_at'])
                    ->delete();
            }
        }

        $order->status = $status;
        $order->fulfillment_status = $status === 'paid'
            ? 'processing'
            : 'unfulfilled';
        $order->paid_at = $status === 'paid' ? now() : null;
        $order->checkout_url = null;
        $order->save();
    }
}