<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tourist destination gallery photos. Files live on the public disk; only the relative path is stored.
 * Rows are removed with their parent. Application code deletes the files.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destination_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_id')->constrained('destinations')->cascadeOnDelete();
            $table->string('path');
            $table->string('caption', 150)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['destination_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destination_images');
    }
};
