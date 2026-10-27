<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the Department of Tourism's monthly accommodation return asks for and
 * the booking never recorded: how the party splits by sex, and where it
 * travelled from.
 *
 * The office has to file that return every month. Until now the only figures
 * the system could give it were arrivals, nights and rooms — the sex and
 * origin columns had to be counted by hand from each resort's guest register,
 * which is the work this is meant to save.
 *
 * All three are nullable on purpose. Reservations made before this existed
 * have no such information, and defaulting them to zero would report "no male
 * guests, no female guests" about parties nobody ever asked. Null means "not
 * recorded", and the report counts those separately rather than as zeroes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('guests_male')->nullable()->after('guests');
            $table->unsignedSmallInteger('guests_female')->nullable()->after('guests_male');
            $table->string('origin', 20)->nullable()->after('guests_female');

            // The report groups by resort and origin over a date range.
            $table->index(['resort_id', 'origin']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['resort_id', 'origin']);
            $table->dropColumn(['guests_male', 'guests_female', 'origin']);
        });
    }
};
