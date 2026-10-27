<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The PWD side of the room's discount policy, alongside the child
 * policy added in 2026_10_19_112511.
 *
 * Off by default. Philippine law gives persons with disability 20% off
 * accommodation, but the figure is the resort's to enter and confirm,
 * so nothing is discounted until an owner switches it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            if (! Schema::hasColumn('rooms', 'pwd_discount_enabled')) {
                $table->boolean('pwd_discount_enabled')
                    ->default(false)
                    ->after('child_discount_max_age');
            }

            if (! Schema::hasColumn('rooms', 'pwd_discount_percent')) {
                $table->unsignedTinyInteger('pwd_discount_percent')
                    ->default(0)
                    ->after('pwd_discount_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['pwd_discount_enabled', 'pwd_discount_percent'],
                fn ($column) => Schema::hasColumn('rooms', $column)
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
