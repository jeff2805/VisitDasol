<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * About Page Management is gone, and so is the table behind it.
 *
 * The page went back to being a Blade template, so nothing reads or writes
 * these rows any more. A table left standing with no code behind it is the
 * kind of thing that survives to production and puzzles whoever meets it
 * next, so it goes with the feature.
 *
 * The earlier create-migration is left in place rather than deleted: it has
 * already run on this database and removing the file would leave a row in
 * `migrations` pointing at nothing, and a rollback that skipped a step.
 * This drops what that one made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('about_sections');
    }

    /**
     * Rebuilt empty, matching the original.
     *
     * Rolling back brings the table back but not its contents — the words
     * that were in it went with the feature, and the About page's wording
     * lives in the template now. Anyone restoring this is restoring the
     * shape, not the writing.
     */
    public function down(): void
    {
        Schema::create('about_sections', function (Blueprint $table): void {
            $table->id();
            $table->string('section_key', 30)->unique();
            $table->json('draft')->nullable();
            $table->json('published')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }
};
