
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'adults')) {
                $table->unsignedSmallInteger('adults')->default(1);
            }

            if (! Schema::hasColumn('bookings', 'children')) {
                $table->unsignedSmallInteger('children')->default(0);
            }

            if (! Schema::hasColumn('bookings', 'pwd_guests')) {
                $table->unsignedSmallInteger('pwd_guests')->default(0);
            }

            if (! Schema::hasColumn('bookings', 'subtotal_amount')) {
                $table->decimal('subtotal_amount', 10, 2)->default(0);
            }

            if (! Schema::hasColumn('bookings', 'child_discount_amount')) {
                $table->decimal('child_discount_amount', 10, 2)->default(0);
            }

            if (! Schema::hasColumn('bookings', 'pwd_discount_amount')) {
                $table->decimal('pwd_discount_amount', 10, 2)->default(0);
            }

            if (! Schema::hasColumn('bookings', 'discount_total')) {
                $table->decimal('discount_total', 10, 2)->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $columns = [
                'adults',
                'children',
                'pwd_guests',
                'subtotal_amount',
                'child_discount_amount',
                'pwd_discount_amount',
                'discount_total',
            ];

            $existingColumns = array_filter(
                $columns,
                fn ($column) => Schema::hasColumn('bookings', $column)
            );

            if (! empty($existingColumns)) {
                $table->dropColumn(array_values($existingColumns));
            }
        });
    }
};
