<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each resort asks for up front, and by when.
 *
 * One row per resort, the way resort_manual_payment_accounts is. A resort
 * with no row keeps the portal's defaults, so this table never has to be
 * backfilled and an owner who never opens the settings page is not left
 * with a listing that cannot be booked.
 *
 * version is bumped on every save. Bookings snapshot it, so a reservation
 * can always say which edition of the policy it was made under even after
 * the owner has changed their terms several times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resort_payment_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resort_id')->unique()->constrained()->cascadeOnDelete();

            // none | deposit | full
            $table->string('requirement', 20)->default('deposit');
            $table->unsignedTinyInteger('deposit_percent')->default(30);

            // How long the guest has, and what the clock starts from.
            $table->unsignedSmallInteger('deadline_hours')->default(24);
            $table->string('deadline_reference', 20)->default('confirmation');

            // What to ask for when arrival is close. full | deposit | on_site
            $table->string('last_minute_rule', 20)->default('full');
            $table->unsignedSmallInteger('last_minute_hours')->default(48);

            $table->boolean('allow_on_site')->default(true);

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resort_payment_policies');
    }
};
