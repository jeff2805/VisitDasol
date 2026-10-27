<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The things a resort offers besides the room.
 *
 * Kayak hire, a banana boat ride, island hopping, a massage. A resort's
 * own list, written by the owner, priced by the owner.
 *
 * NOT A ROOM AND NOT INVENTORY. There is no count of kayaks here on
 * purpose: whether a boat goes out depends on the weather, the fuel and
 * whether the boatman turned up, and none of those are things this
 * system can know. A guest asks; the resort answers. That is why the
 * booking side of this is a request and not a purchase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resort_extras', function (Blueprint $table) {
            $table->id();

            $table->foreignId('resort_id')
                ->constrained('resorts')
                ->cascadeOnDelete();

            $table->string('name', 120);
            $table->text('description')->nullable();

            /*
             * Money, as every other rate in this project: decimal, read
             * into centavos before any arithmetic happens.
             */
            $table->decimal('price', 10, 2);

            /*
             * What the price is for. A kayak is by the hour, a banana
             * boat by the head, island hopping by the boat. Charging a
             * per-boat price per person is how a family of six gets a
             * bill six times too big.
             */
            $table->string('price_unit', 20)->default('per_booking');

            // The most a guest may ask for in one go. Stops a typo in a
            // number box turning into a request for 500 kayaks.
            $table->unsignedSmallInteger('max_quantity')->default(10);

            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // One name per resort, so a guest choosing from a list is
            // never offered "Kayak" twice at two prices.
            $table->unique(['resort_id', 'name'], 'resort_extras_name_unique');
            $table->index(['resort_id', 'active'], 'resort_extras_live_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resort_extras');
    }
};
