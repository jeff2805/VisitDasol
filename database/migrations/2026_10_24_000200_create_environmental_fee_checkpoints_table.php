<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the environmental fee is actually collected.
 *
 * Named one at a time by the tourism office. NOT derived from the resort
 * list and NOT from the barangay list: a municipality collects at the
 * places an ordinance names, which is usually a handful of roads and
 * beach entrances, and designating all eighteen barangays as toll points
 * because the system happens to know their names would be an invention.
 *
 * Coordinates are checked against the municipal bounds, so a checkpoint
 * cannot be dropped in the next town.
 *
 * Deactivated, never deleted: a checkpoint that has taken money is part
 * of the record of where that money came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environmental_fee_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('barangay', 50);
            $table->text('description')->nullable();

            // Same precision as resorts and destinations.
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            $table->boolean('active')->default(true);

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['active', 'barangay']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environmental_fee_checkpoints');
    }
};
