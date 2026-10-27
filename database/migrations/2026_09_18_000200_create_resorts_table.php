<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resort listings. New resorts start as "pending" and become public only
 * after MTO approval. Owners are never hard-deleted while they own a resort.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resorts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('slug', 170)->unique();
            $table->text('description');
            $table->string('address');
            $table->string('barangay', 60);
            $table->string('municipality', 60)->default('Dasol');
            $table->string('province', 60)->default('Pangasinan');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('contact_number', 20);
            $table->string('email')->nullable();
            $table->string('website_url')->nullable();
            $table->time('check_in_time');
            $table->time('check_out_time');
            $table->string('main_image')->nullable();
            $table->string('status', 20)->default('pending');
            $table->boolean('is_featured')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();
            $table->timestamps();

            $table->index(['status', 'is_featured']);
            $table->index('barangay');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resorts');
    }
};
