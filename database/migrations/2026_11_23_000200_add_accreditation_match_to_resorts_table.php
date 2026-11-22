<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the accreditation check found, recorded on the application itself.
 *
 * The specification asked for a resort_applications table to carry these.
 * This system has no such table: a resort application IS the resorts row,
 * sitting at status "pending" with submitted_at set, and
 * Admin\ResortApplicationController reviews it in place. Inventing a
 * parallel table would mean two rows per resort and a question — which one
 * is the application? — that nothing in the existing code could answer.
 *
 * WHY THE RESULT IS STORED AND NOT COMPUTED WHEN THE PAGE IS DRAWN.
 * The office replaces its accredited list whenever accreditation is
 * renewed. A result worked out live would then change retroactively: an
 * application approved last March, when the resort was on the list, would
 * display today as never having matched. The office asked for the uploaded
 * list to be the reference and the applications to keep their own history,
 * so the finding is written down when it is made.
 *
 * accreditation_no is copied rather than only pointed at for the same
 * reason — the number stays legible after the row it came from is replaced
 * by the next upload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resorts', function (Blueprint $table) {
            // listed / not_listed / needs_review, or null before any check.
            $table->string('accreditation_match_status', 20)->nullable()->after('review_remarks');

            $table->foreignId('matched_accredited_resort_id')
                ->nullable()
                ->after('accreditation_match_status')
                ->constrained('accredited_resorts')
                ->nullOnDelete();

            // The number as it read at the time of the check. Survives the
            // next upload replacing the row above.
            $table->string('matched_accreditation_no', 60)->nullable()->after('matched_accredited_resort_id');

            // Why the matcher decided what it did, in words the office can
            // read. Without it "needs review" is an instruction with no
            // reason attached, and the reviewer has to guess what to check.
            $table->string('accreditation_match_note', 255)->nullable()->after('matched_accreditation_no');

            $table->timestamp('accreditation_checked_at')->nullable()->after('accreditation_match_note');
        });
    }

    public function down(): void
    {
        Schema::table('resorts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('matched_accredited_resort_id');

            $table->dropColumn([
                'accreditation_match_status',
                'matched_accreditation_no',
                'accreditation_match_note',
                'accreditation_checked_at',
            ]);
        });
    }
};
