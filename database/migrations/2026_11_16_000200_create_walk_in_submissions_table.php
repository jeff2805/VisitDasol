<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened to a walk-in record on its way through the tourism office.
 *
 * One row per event — submitted, returned, accepted, resubmitted — never
 * updated and never deleted. The current state lives on the booking itself
 * so a list can be filtered and indexed without touching this table; this is
 * the trail behind that state, which is what both the resort and the office
 * actually need when a figure is questioned months later.
 *
 * WHY NOT activity_logs. That table is the site-wide audit trail and this
 * module writes to it as well. What it cannot do is carry a reviewer's
 * remarks as a first-class, queryable column: its properties are JSON, and
 * "show me every record this office sent back, with the reason" is a report
 * the office will ask for. Both are written, each for what it is good at.
 *
 * The remarks belong to the EVENT, not the booking. bookings.return_remarks
 * holds the latest reason so a screen can show it without a join; the full
 * series of them lives here, so a record returned three times still says
 * what was wrong each time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('walk_in_submissions', function (Blueprint $table): void {
            $table->id();

            // Cascades, matching payments: the trail describes one booking
            // and means nothing without it. Bookings are not deleted in
            // normal use, so this is a safeguard rather than a routine path.
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            // submitted | returned | accepted | resubmitted
            $table->string('action', 20);

            // The status the record moved INTO, so the trail can be read on
            // its own without replaying every earlier row.
            $table->string('resulting_status', 20);

            // Who did it: the resort owner for a submission, the office for a
            // decision. Nulls if that account is later deleted; the row and
            // its remarks survive, which is the point of an audit trail.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // Required of the office when returning a record, and otherwise
            // optional: a resort may say what they corrected.
            $table->text('remarks')->nullable();

            $table->timestamps();

            // The history of one record, oldest first.
            $table->index(['booking_id', 'id']);

            // "Everything this office returned last month", across resorts.
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('walk_in_submissions');
    }
};
