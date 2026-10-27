<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An email address the resort can reply to.
 *
 * Until now a resort could only phone a guest back. The booking form already
 * asks for a name and a number, and an address belongs beside them: it is
 * how most resorts answer a request.
 *
 * Nullable, because every reservation made before this column existed has
 * none. The form fills it from the guest's account, so in practice it is
 * always set from here on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('contact_email', 255)->nullable()->after('contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('contact_email');
        });
    }
};
