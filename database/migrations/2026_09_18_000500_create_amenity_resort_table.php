<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which amenities each resort offers (many-to-many).
 * The composite primary key prevents duplicate pairs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amenity_resort', function (Blueprint $table) {
            $table->foreignId('amenity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resort_id')->constrained()->cascadeOnDelete();

            $table->primary(['amenity_id', 'resort_id']);
            $table->index('resort_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amenity_resort');
    }
};
