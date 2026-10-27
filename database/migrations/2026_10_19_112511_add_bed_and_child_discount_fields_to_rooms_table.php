
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            // Number of beds available in each room unit.
            $table->unsignedTinyInteger('bed_count')
                ->default(1)
                ->after('capacity');

            // Optional child discount configured by the resort.
            $table->boolean('child_discount_enabled')
                ->default(false)
                ->after('bed_count');

            $table->unsignedTinyInteger('child_discount_percent')
                ->default(0)
                ->after('child_discount_enabled');

            // Minimum and maximum child ages eligible for this policy.
            $table->unsignedTinyInteger('child_discount_min_age')
                ->default(0)
                ->after('child_discount_percent');

            $table->unsignedTinyInteger('child_discount_max_age')
                ->default(12)
                ->after('child_discount_min_age');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn([
                'bed_count',
                'child_discount_enabled',
                'child_discount_percent',
                'child_discount_min_age',
                'child_discount_max_age',
            ]);
        });
    }
};
