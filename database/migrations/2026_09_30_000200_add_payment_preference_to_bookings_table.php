<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the guest intends to pay, asked on the booking form.
 *
 * Not a payment, and deliberately not a payments row: money only moves once
 * the resort has confirmed, and the payments table is the record of money
 * that actually moved. This column holds the guest's answer to "GCash, or
 * settle at the resort?" so that the confirmation can lead straight to the
 * right next step instead of asking again.
 *
 * Nullable, because every reservation made before this column existed has
 * none, and those guests arranged payment with the resort directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('payment_preference', 20)->nullable()->after('total_amount');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('payment_preference');
        });
    }
};
