<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reservations. One room type per booking, with a quantity (rooms_booked).
 *
 * - booking_reference is the public identifier (VD-YYYYMMDD-XXXXX).
 * - resort_id is copied from the room on the server, never from a form.
 * - unit_price and total_amount are snapshots calculated on the server.
 * - A stay occupies the nights [check_in_date, check_out_date).
 * - Booking history is never deleted, so the foreign keys restrict deletion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_reference', 20)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('resort_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->unsignedSmallInteger('nights');
            $table->unsignedSmallInteger('guests');
            $table->unsignedSmallInteger('rooms_booked')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total_amount', 12, 2);
            $table->string('contact_name', 150);
            $table->string('contact_phone', 20);
            $table->text('special_requests')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('owner_remarks')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Availability lookups (Phase 11)
            $table->index(['room_id', 'status', 'check_in_date', 'check_out_date']);
            // Owner lists and reports
            $table->index(['resort_id', 'status', 'check_in_date']);
            // Tourist booking history
            $table->index(['user_id', 'created_at']);
            // MTO monitoring and reports
            $table->index(['status', 'check_in_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
