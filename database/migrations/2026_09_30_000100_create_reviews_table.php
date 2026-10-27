<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest reviews of a resort.
 *
 * A review belongs to a BOOKING, not merely to a resort, and booking_id is
 * unique. That one constraint carries most of the integrity of the whole
 * feature:
 *
 *   - only someone who actually stayed can write one, because a review needs
 *     a completed booking to hang from;
 *   - nobody can post twice about the same stay;
 *   - a guest who genuinely stayed three times may write three reviews.
 *
 * resort_id is also stored, though it could be reached through the booking.
 * Averages are read on every resort listing, and reaching through bookings
 * for each one would cost a join per row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();

            // One review per stay, enforced by the database rather than by
            // remembering to check.
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('resort_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();

            // published | hidden. Defaults to published: the tourism office
            // moderates after the fact rather than holding every review up.
            $table->string('status', 20)->default('published');

            // The resort's public answer.
            $table->text('owner_reply')->nullable();
            $table->timestamp('owner_replied_at')->nullable();

            // Why the office took it down, kept for the audit trail.
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('hidden_at')->nullable();
            $table->string('hidden_reason', 255)->nullable();

            $table->timestamps();

            // The query behind every average and every review list.
            $table->index(['resort_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
