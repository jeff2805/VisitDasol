<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a reservation is an overnight stay, a day tour or a night tour.
 *
 * Defaults to overnight, which is what every reservation made before
 * this column existed was.
 *
 * HOW A TOUR IS STORED, because it is not obvious and getting it wrong
 * would oversell every resort in Dasol:
 *
 *   A tour is ONE date to the guest, but it is stored the way every
 *   other booking is — check_out_date is the day AFTER, and nights is
 *   zero. That is deliberate. AvailabilityService counts the nights in
 *   the half-open range [check_in, check_out), and skips any booking
 *   where the two dates are equal. A tour stored on a single date would
 *   hold NO inventory at all, and a resort could sell the same cottage
 *   to an unlimited number of day groups.
 *
 *   Storing it as [D, D+1) blocks date D exactly as one night does, and
 *   the sweep needs no changes. nights = 0 is what tells the pricing
 *   and the screens that nobody slept there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'booking_type')) {
                $table->string('booking_type', 20)
                    ->default('overnight')
                    ->after('check_out_time');

                $table->index(['booking_type', 'check_in_date'], 'bookings_type_date_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'booking_type')) {
                $table->dropIndex('bookings_type_date_index');
                $table->dropColumn('booking_type');
            }
        });
    }
};
