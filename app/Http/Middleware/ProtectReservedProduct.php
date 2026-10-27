<?php

namespace App\Http\Middleware;

use App\Models\Product;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProtectReservedProduct
{
    public function handle(Request $request, Closure $next)
    {
        return DB::transaction(function () use ($request, $next) {
            $parameter = $request->route('product');

            $id = $parameter instanceof Product
                ? $parameter->id
                : $parameter;

            $product = Product::whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_if(
                $product->reserved_stock > 0,
                409,
                'This product has an active checkout reservation. Wait for payment or expiry before editing.'
            );

            $request->route()->setParameter('product', $product);

            return $next($request);
        });
    }
}