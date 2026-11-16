<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resorts', function (Blueprint $table) {
            $table->string('category', 30)
                ->default('budget')
                ->after('name')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('resorts', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};