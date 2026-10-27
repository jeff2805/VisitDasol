<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resort_manual_payment_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resort_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('account_name', 120);
            $table->string('gcash_number', 30);
            $table->string('qr_image')->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resort_manual_payment_accounts');
    }
};
