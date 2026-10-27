<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a room costs for a day tour and for a night tour.
 *
 * Priced PER BOOKING, not per night: a family at the beach from
 * breakfast to sunset is not buying a night's sleep, and multiplying a
 * nightly rate by zero nights would make the stay free.
 *
 * BOTH NULLABLE, and null is the answer, not a missing default. A room
 * that does not offer day tours has no day-tour price, and the booking
 * form must not offer a visitor something the resort does not sell.
 * Defaulting these to the nightly rate would have every room in Dasol
 * silently advertising tours nobody agreed to run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            if (! Schema::hasColumn('rooms', 'day_tour_price')) {
                $table->decimal('day_tour_price', 10, 2)
                    ->nullable()
                    ->after('price_per_night');
            }

            if (! Schema::hasColumn('rooms', 'night_tour_price')) {
                $table->decimal('night_tour_price', 10, 2)
                    ->nullable()
                    ->after('day_tour_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['day_tour_price', 'night_tour_price'],
                fn ($column) => Schema::hasColumn('rooms', $column)
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
