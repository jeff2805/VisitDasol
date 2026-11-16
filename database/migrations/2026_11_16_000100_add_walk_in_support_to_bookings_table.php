<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Walk-in reservations: guests the resort met at its own gate, recorded
 * afterwards, and sent to the Municipal Tourism Office for the monthly return.
 *
 * ONE TABLE, NOT TWO. A walk-in holds a room, is priced, and is reported in
 * the same way an online stay is, so it is the same row with a different
 * origin. A second table would have meant a second availability sweep, a
 * second pricing path and a second set of reporting queries, each free to
 * drift from the first. AvailabilityService therefore needs no change at all:
 * it counts bookings that hold inventory and never asks where they came from.
 *
 * THE ONE CHANGE THAT TOUCHES EXISTING RESERVATIONS is user_id becoming
 * nullable. A walk-in guest has no account, and nothing may be invented for
 * them: a placeholder user would appear in the user list, in counts of
 * registrations, and in anything that joins users. Every existing row keeps
 * its user_id; only the NOT NULL is lifted.
 *
 * TWO SEPARATE AXES, WHICH IS THE POINT OF THIS MIGRATION:
 *
 *   bookings.status             what the room does    (unchanged)
 *   bookings.submission_status  what the office does  (added here)
 *
 * A walk-in holds its room from the moment it is saved, while still a draft,
 * and keeps holding it when the office returns it for correction. The guest
 * is standing at the resort either way. Acceptance decides only whether the
 * stay is counted in the Department of Tourism return.
 *
 * submission_status is NULL for an online booking, which is how the two
 * sources are told apart in a query without reading booking_source at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            if (! Schema::hasColumn('bookings', 'booking_source')) {
                // Defaulted, so every reservation already in the table is
                // what it has always been: a booking made on this site.
                $table->string('booking_source', 10)
                    ->default('online')
                    ->after('booking_reference');
            }

            // The address a walk-in guest gives at the desk. Free text, and
            // NOT the same thing as bookings.origin, which is the Department's
            // five-way bucket (Pangasinan, Ilocos, NCR, other PH, foreign).
            // The return needs the bucket; the resort's own register needs
            // the address, so both are kept.
            if (! Schema::hasColumn('bookings', 'guest_address')) {
                $table->string('guest_address', 255)->nullable()->after('contact_phone');
            }

            /* The tourism office's review ------------------------------- */

            if (! Schema::hasColumn('bookings', 'submission_status')) {
                $table->string('submission_status', 20)->nullable()->after('owner_remarks');
            }

            if (! Schema::hasColumn('bookings', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('submission_status');
            }

            if (! Schema::hasColumn('bookings', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
            }

            if (! Schema::hasColumn('bookings', 'reviewed_by')) {
                // Who at the office decided. Kept when that account is
                // deleted would be a lie, so it nulls; the submission
                // history keeps the full record either way.
                $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')
                    ->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('bookings', 'return_remarks')) {
                // Why the office sent it back. Required of the reviewer when
                // they return a record, so the resort knows what to correct.
                $table->text('return_remarks')->nullable()->after('reviewed_by');
            }

            if (! Schema::hasColumn('bookings', 'accepted_at')) {
                $table->timestamp('accepted_at')->nullable()->after('return_remarks');
            }
        });

        /*
         * Lifted in its own statement, because a column being altered cannot
         * also be added in the same blueprint on every driver.
         *
         * unsignedBigInteger rather than foreignId: the foreign key already
         * exists and is not being touched, and re-declaring it with
         * foreignId() would try to create it a second time. Both MySQL and
         * PostgreSQL accept a nullability change on a column that a foreign
         * key points out of.
         */
        Schema::table('bookings', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        Schema::table('bookings', function (Blueprint $table): void {
            // The owner's own walk-in list: one resort, its walk-ins, newest
            // arrivals first.
            $table->index(['resort_id', 'booking_source', 'check_in_date'], 'bookings_resort_source_index');

            // The office's review queue, and the filter that keeps unaccepted
            // walk-ins out of the Department return.
            $table->index(['booking_source', 'submission_status'], 'bookings_source_submission_index');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex('bookings_resort_source_index');
            $table->dropIndex('bookings_source_submission_index');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn([
                'booking_source',
                'guest_address',
                'submission_status',
                'submitted_at',
                'reviewed_at',
                'return_remarks',
                'accepted_at',
            ]);
        });

        /*
         * NOT reversed: user_id does not go back to NOT NULL.
         *
         * Any walk-in recorded while this migration was in place has no
         * account to point at, and restoring the constraint would either fail
         * outright or demand that real reservations be deleted to satisfy it.
         * Rolling back the columns is safe; rolling back the nullability is
         * not, so it is left alone deliberately.
         */
    }
};
