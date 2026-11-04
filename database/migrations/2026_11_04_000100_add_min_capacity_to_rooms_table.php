<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The smallest party a room type will take.
 *
 * rooms.capacity has always been the ceiling. There was no floor, so a
 * resort whose beach cottage only makes sense for a group of four had no way
 * to say so, and a single guest could book the whole thing at the per-unit
 * rate. Owners were handling it by declining the request afterwards, which
 * costs the guest a wait and the owner a conversation.
 *
 * Nullable rather than defaulted to 1: null means "no minimum", which is
 * what every room already in the table means, and is different from an
 * owner having deliberately typed 1. Existing rows are therefore unchanged
 * and every existing booking stays valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            if (! Schema::hasColumn('rooms', 'min_capacity')) {
                $table->unsignedTinyInteger('min_capacity')
                    ->nullable()
                    ->after('capacity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            if (Schema::hasColumn('rooms', 'min_capacity')) {
                $table->dropColumn('min_capacity');
            }
        });
    }
};
