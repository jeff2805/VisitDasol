<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The municipality's own GCash details for the environmental fee.
 *
 * Mirrors resort_manual_payment_accounts, and is deliberately a
 * SEPARATE table rather than a row in it. That table belongs to a
 * resort; this money is the municipality's, and a foreign key between
 * them would be the first step towards the two being reconciled
 * together.
 *
 * ONE ROW. A municipality has one treasury, not one per checkpoint, and
 * a QR per gate would be a QR nobody could reconcile.
 *
 * WHAT THIS IS NOT: a payment gateway. A QR code is an instruction to
 * the payer, not a connection to anybody's wallet. Nothing in this
 * system can see money arrive in GCash, so every payment made this way
 * waits for a person to check the app and say it landed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environmental_fee_accounts', function (Blueprint $table): void {
            $table->id();

            $table->string('account_name', 120);
            $table->string('gcash_number', 30);
            $table->string('qr_image')->nullable();
            $table->text('instructions')->nullable();

            // Off until the office is ready to accept GCash at the gates.
            $table->boolean('active')->default(false);

            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environmental_fee_accounts');
    }
};
