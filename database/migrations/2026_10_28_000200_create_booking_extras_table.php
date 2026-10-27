<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a guest asked for on top of the room, and what the resort said.
 *
 * A REQUEST, NOT A PURCHASE. A guest ticks "banana boat" and the resort
 * answers yes or no, because whether a boat goes out depends on the
 * weather and the boatman and not on this database. Every row starts at
 * "requested" and only an owner moves it.
 *
 * THE PRICE IS SNAPSHOTTED. name, price and price_unit are copied here
 * at the moment of asking, and never read back from resort_extras. An
 * owner who raises the kayak price next month must not change what a
 * guest was quoted in writing last month — the same rule the room rate
 * and the payment terms already follow.
 *
 * NOT PART OF total_amount. These are settled at the resort, so the
 * booking total stays what the room costs and the deposit, the
 * discounts and the payment terms are untouched by anything here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_extras', function (Blueprint $table) {
            $table->id();

            $table->foreignId('booking_id')
                ->constrained('bookings')
                ->cascadeOnDelete();

            /*
             * Kept, not cascaded. An owner who retires a kayak must not
             * erase the record that a guest once asked for one — the
             * snapshot below is what the row means, and the link is
             * only for "which of my listings was this".
             */
            $table->foreignId('resort_extra_id')->nullable()
                ->constrained('resort_extras')
                ->nullOnDelete();

            // The snapshot. What the guest was shown, frozen.
            $table->string('name', 120);
            $table->decimal('unit_price', 10, 2);
            $table->string('price_unit', 20);
            $table->unsignedSmallInteger('quantity')->default(1);

            /*
             * quantity x unit_price, worked out on the server and
             * stored so a printed sheet and a screen cannot disagree
             * about the arithmetic.
             */
            $table->decimal('amount', 12, 2);

            $table->string('status', 20)->default('requested');

            // Why the resort said no, or anything the guest should know
            // before they arrive expecting it.
            $table->text('owner_note')->nullable();

            $table->foreignId('answered_by')->nullable()
                ->constrained('users')->nullOnDelete();

            /*
             * dateTime, not timestamp. MySQL gives the first NOT NULL
             * TIMESTAMP column an implicit ON UPDATE CURRENT_TIMESTAMP,
             * which silently rewrites the moment an answer was given —
             * a bug this project has already been bitten by on
             * collections.collected_at.
             */
            $table->dateTime('answered_at')->nullable();

            $table->timestamps();

            // One line per extra per booking. Asking twice means
            // changing the quantity, not stacking a second row.
            $table->unique(['booking_id', 'resort_extra_id'], 'booking_extras_once_unique');
            $table->index(['booking_id', 'status'], 'booking_extras_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_extras');
    }
};
