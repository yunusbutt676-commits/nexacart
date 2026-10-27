<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    public function index(Request $request)
    {
        $items = CartItem::with('product.category')
            ->where('user_id', $request->user()->id)
            ->orderBy('id')
            ->get()
            ->map(function (CartItem $item) {
                $product = $item->product;

                $available = $product
                    && ! $product->trashed()
                    && $product->is_active
                    && $product->stock >= $item->quantity;

                return [
                    'id' => $item->id,
                    'quantity' => $item->quantity,
                    'product' => $product,
                    'available' => (bool) $available,
                    'line_total' => ($product?->price ?? 0) * $item->quantity,
                ];
            });

        return response()->json([
            'items' => $items,
            'subtotal' => $items->sum('line_total'),
            'quantity' => $items->sum('quantity'),
            'ready' => $items->isNotEmpty()
                && $items->every(fn ($item) => $item['available']),
            'currency' => 'PKR',
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        DB::transaction(function () use ($request, $data) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();

            $product = Product::where('is_active', true)
                ->findOrFail($data['product_id']);

            $item = CartItem::where('user_id', $request->user()->id)
                ->where('product_id', $product->id)
                ->first();

            $quantity = ($item?->quantity ?? 0) + $data['quantity'];

            abort_if(
                $quantity > min(99, $product->stock),
                422,
                'Requested quantity exceeds available stock or the 99-item limit.'
            );

            if (! $item) {
                abort_if(
                    CartItem::where('user_id', $request->user()->id)->count() >= 50,
                    422,
                    'Your cart can contain up to 50 different products.'
                );
            }

            CartItem::updateOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'product_id' => $product->id,
                ],
                ['quantity' => $quantity]
            );
        });

        return $this->index($request);
    }

    public function update(Request $request, int $item)
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        DB::transaction(function () use ($request, $item, $data) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();

            $cartItem = CartItem::where('user_id', $request->user()->id)
                ->findOrFail($item);

            $product = Product::where('is_active', true)
                ->find($cartItem->product_id);

            abort_unless($product, 422, 'This product is no longer available.');

            abort_if(
                $data['quantity'] > $product->stock,
                422,
                'Requested quantity exceeds available stock.'
            );

            $cartItem->update($data);
        });

        return $this->index($request);
    }

    public function destroy(Request $request, int $item)
    {
        CartItem::where('user_id', $request->user()->id)
            ->findOrFail($item)
            ->delete();

        return $this->index($request);
    }
}