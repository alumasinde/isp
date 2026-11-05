<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mpesa_payments', function (Blueprint $table) {
            $table->id();
            $table->string('customer_unique_id', 64)->index();
            $table->string('purpose', 32)->default('bill');
            $table->string('phone', 20)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 8)->default('KES');
            $table->string('account_reference', 32)->nullable();
            $table->string('merchant_request_id', 64)->nullable()->index();
            $table->string('checkout_request_id', 64)->nullable()->unique();
            $table->string('mpesa_receipt', 32)->nullable()->unique();
            $table->string('status', 16)->default('pending')->index();
            $table->string('result_code', 8)->nullable();
            $table->text('result_desc')->nullable();
            $table->json('raw_response')->nullable();
            $table->json('raw_callback')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_payments');
    }
};
