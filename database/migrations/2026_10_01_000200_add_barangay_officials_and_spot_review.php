<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a barangay send in its own tourist spots.
 *
 * Dasol has 18 barangays and one tourism office. Asking that office to write
 * up every waterfall and viewdeck in the municipality is why the tourist spot
 * pages were still carrying sample data: the people who know each place are
 * in the barangays, not at the municipal hall.
 *
 * So a barangay official gets an account tied to one barangay, writes up the
 * spots they know, and sends them in. Nothing they write is public until the
 * office approves it — the office stays the publisher, which is what keeps a
 * municipal site trustworthy.
 *
 * Two additions:
 *   users.barangay        which barangay an official speaks for. Null for
 *                         every other role, and meaningless there.
 *   destinations review columns, mirroring the resort application columns,
 *                         so a submission can be approved, sent back with a
 *                         reason, and corrected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('barangay', 50)->nullable()->after('home_country');
        });

        Schema::table('destinations', function (Blueprint $table): void {
            $table->timestamp('submitted_at')->nullable()->after('is_featured');
            $table->foreignId('reviewed_by')->nullable()->after('submitted_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            // Why the office sent it back. Shown to the barangay, never public.
            $table->text('review_remarks')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('destinations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['submitted_at', 'reviewed_at', 'review_remarks']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('barangay');
        });
    }
};
