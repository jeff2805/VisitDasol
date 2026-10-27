<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the room's policy said on the day the request was sent.
 *
 * The amounts are already snapshotted (2026_10_19_114242), but an
 * amount on its own cannot be checked: an owner who changes the rate
 * next week would leave every past booking looking miscalculated.
 * The rates and the bed count are copied here for the same reason
 * unit_price is — so a reservation explains itself years later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'beds')) {
                $table->unsignedSmallInteger('beds')->default(0);
            }

            if (! Schema::hasColumn('bookings', 'child_discount_percent')) {
                $table->unsignedTinyInteger('child_discount_percent')
                    ->default(0);
            }

            if (! Schema::hasColumn('bookings', 'pwd_discount_percent')) {
                $table->unsignedTinyInteger('pwd_discount_percent')
                    ->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['beds', 'child_discount_percent', 'pwd_discount_percent'],
                fn ($column) => Schema::hasColumn('bookings', $column)
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
