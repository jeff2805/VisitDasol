<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online payments against a reservation.
 *
 * One reservation may have several rows: a guest can abandon a checkout and
 * start another, and each attempt is kept so the office can see what happened
 * rather than only the attempt that worked.
 *
 * No card number, account name, or wallet detail is ever stored here. Both
 * gateways take the payment on their own pages, so this table only ever holds
 * an amount, a status, and the reference the gateway gave back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('payment_reference', 32)->unique();

            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            // paymongo | paypal
            $table->string('provider', 20);
            // What the guest actually paid with (gcash, card, paypal...), as
            // reported by the gateway. Null until they have chosen.
            $table->string('method', 30)->nullable();
            // The gateway's own id for the checkout, used to ask it later what
            // became of the payment.
            $table->string('provider_reference', 191)->nullable();

            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('PHP');

            $table->string('status', 20)->default('pending');
            $table->text('checkout_url')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('paid_at')->nullable();

            // The last answer the gateway gave, kept for reconciliation when a
            // figure is disputed. Never contains payment credentials.
            $table->json('payload')->nullable();

            $table->timestamps();

            $table->index(['booking_id', 'status']);
            $table->index(['provider', 'provider_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
