<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('reserved_stock')->default(0);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();

            $table->foreignId('user_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('email');
            $table->string('status', 20)->default('pending');
            $table->string('fulfillment_status', 20)->default('unfulfilled');

            $table->char('currency', 3)->default('pkr');
            $table->unsignedInteger('total');

            $table->json('items');
            $table->json('shipping_address');
            $table->json('stripe_payload');

            $table->string('stripe_session_id')->nullable()->unique();
            $table->text('checkout_url')->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('reserved_stock');
        });
    }
};