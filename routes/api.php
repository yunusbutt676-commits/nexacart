<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\OrderController;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    DB::select('SELECT 1');

    return response()->json([
        'status' => 'ok',
        'database' => DB::connection()->getDatabaseName(),
    ]);
});

Route::middleware('throttle:account-recovery')->group(function () {
    Route::post('/account-tools/forgot-password', [
        AccountSettingsController::class,
        'forgotPassword',
    ]);

    Route::post('/account-tools/reset-password', [
        AccountSettingsController::class,
        'resetPassword',
    ]);
});

Route::middleware(['auth:sanctum', 'active'])
    ->group(function () {
        Route::middleware('throttle:account-settings')->group(function () {
            Route::put('/account-tools/profile', [
                AccountSettingsController::class,
                'updateProfile',
            ]);

            Route::put('/account-tools/password', [
                AccountSettingsController::class,
                'changePassword',
            ]);
        });

        Route::post('/account-tools/verification-email', [
            AccountSettingsController::class,
            'sendVerification',
        ])->middleware('throttle:verification-email');

        Route::middleware(['admin', 'throttle:admin-users'])
            ->group(function () {
                Route::get('/account-tools/users', [
                    AccountSettingsController::class,
                    'users',
                ]);

                Route::patch('/account-tools/users/{user}', [
                    AccountSettingsController::class,
                    'updateUserStatus',
                ]);
            });
});

Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:5,1');

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:6,1');

Route::middleware('throttle:120,1')->group(function () {
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{slug}', [ProductController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/checkout', [CheckoutController::class, 'store'])
    ->middleware('throttle:10,1');

    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{reference}', [OrderController::class, 'show']);

    Route::post('/orders/{reference}/pay', [CheckoutController::class, 'pay'])
        ->middleware('throttle:10,1');

    Route::post('/orders/{reference}/sync', [CheckoutController::class, 'sync'])
        ->middleware('throttle:10,1');

    Route::post('/orders/{reference}/cancel', [CheckoutController::class, 'cancel'])
        ->middleware('throttle:10,1');

    Route::post('/ai/chat', AiController::class)
        ->middleware('throttle:shopping-ai');

    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::middleware('throttle:60,1')->group(function () {
        Route::get('/cart', [CartController::class, 'index']);
        Route::post('/cart', [CartController::class, 'store']);
        Route::patch('/cart/{item}', [CartController::class, 'update']);
        Route::delete('/cart/{item}', [CartController::class, 'destroy']);

        Route::get('/address', [AddressController::class, 'show']);
        Route::put('/address', [AddressController::class, 'update']);
    });

    Route::prefix('admin')->middleware(['admin', 'throttle:60,1'])
        ->group(function () {
            Route::get('/orders', [OrderController::class, 'adminIndex']);
            Route::patch('/orders/{reference}', [OrderController::class, 'update']);
            Route::get('/overview', fn () => response()->json([
                'users' => User::count(),
                'customers' => User::where('role', 'customer')->count(),
                'blocked' => User::where('is_blocked', true)->count(),
            ]));

            Route::post('/categories', [CategoryController::class, 'store']);
            Route::put('/categories/{category}', [CategoryController::class, 'update']);
            Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

            Route::get('/products', [ProductController::class, 'adminIndex']);
            Route::post('/products', [ProductController::class, 'store']);
            Route::put('/products/{product}', [ProductController::class, 'update'])
                ->middleware('stock.edit');

            Route::delete('/products/{product}', [ProductController::class, 'destroy'])
                ->middleware('stock.edit');
            });
});