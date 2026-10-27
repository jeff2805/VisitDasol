<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catches up databases where the day-tour migrations ran as empty stubs.
 *
 * 2026_10_19_210513 and ..._210522 were created with nothing in them and
 * were run in that state, so they are recorded as done. Filling them in
 * afterwards changes nothing for any database that has already run them
 * — which is every existing one. This adds the columns there.
 *
 * Everything is guarded by hasColumn, so a fresh install that picks the
 * columns up from the now-filled stubs passes straight through here, and
 * running it twice is harmless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            if (! Schema::hasColumn('rooms', 'day_tour_price')) {
                $table->decimal('day_tour_price', 10, 2)->nullable()->after('price_per_night');
            }

            if (! Schema::hasColumn('rooms', 'night_tour_price')) {
                $table->decimal('night_tour_price', 10, 2)->nullable()->after('day_tour_price');
            }
        });

        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'booking_type')) {
                $table->string('booking_type', 20)->default('overnight')->after('check_out_time');
                $table->index(['booking_type', 'check_in_date'], 'bookings_type_date_index');
            }
        });
    }

    /**
     * Deliberately empty.
     *
     * The columns belong to the two migrations named after them; this
     * one only fills a gap left by their having run blank. Dropping
     * them here would take them away from a fresh install that got them
     * from the right place.
     */
    public function down(): void {}
};
