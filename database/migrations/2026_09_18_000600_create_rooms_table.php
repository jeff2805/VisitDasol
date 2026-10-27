<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room TYPES offered by a resort. total_rooms is the number of identical
 * units of this type. Availability is never stored; it is calculated from
 * total_rooms and overlapping bookings (Phase 11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resort_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('room_type', 30);
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('capacity');
            $table->decimal('price_per_night', 10, 2);
            $table->unsignedSmallInteger('total_rooms');
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['resort_id', 'name']);
            $table->index(['resort_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
