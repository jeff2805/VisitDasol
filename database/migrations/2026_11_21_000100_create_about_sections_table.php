<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the public About page says, written by the Municipal Tourism Office.
 *
 * ONE ROW PER SECTION, AND TWO COPIES OF EVERY SECTION.
 *
 * `draft` is what the office is working on. `published` is what visitors
 * read. Publishing copies the one into the other, and nothing else moves.
 *
 * That shape is the whole answer to "saving a draft must not change the
 * public page": the public page does not read the draft column at all, so
 * it cannot show a half-written sentence however hard anybody tries. The
 * preview reads the draft, which is what a preview is for.
 *
 * WHY JSON RATHER THAN A COLUMN PER FIELD. The seven sections hold
 * genuinely different things — a hero has a label and a background, the
 * heritage section has a gallery with captions, the destinations section
 * holds an ordered list of ids from another table. A column per field would
 * be a table of forty mostly-null columns, and a migration every time the
 * office wants a second subheading. The shape of each payload is declared
 * in App\Enums\AboutSection, which is also where the defaults live.
 *
 * NO IMAGES ARE STORED HERE, only their paths. Files go on the public disk
 * through ImageUploadService, the same as resort and destination pictures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('about_sections', function (Blueprint $table): void {
            $table->id();

            // hero | municipality | history | heritage | destinations |
            // location | owner_cta. Unique: a section is one thing, and two
            // rows claiming to be the hero would make "which one shows?"
            // a question nobody can answer.
            $table->string('section_key', 30)->unique();

            $table->json('draft')->nullable();
            $table->json('published')->nullable();

            /*
             * Who touched it and when. The office is a group of people and
             * the About page is the municipality's public face; "who
             * changed this and when" is the first question asked when a
             * date or a figure on it turns out to be wrong.
             */
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('about_sections');
    }
};
