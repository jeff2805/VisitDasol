<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The municipality's own reference for a member of staff.
 *
 * ADDITIVE ONLY. One nullable column; no existing row is touched and no
 * account is altered, so every collection, receipt, remittance and
 * audit entry already on file keeps pointing at exactly the user it
 * pointed at before.
 *
 * Nullable because most accounts are not staff — a tourist has no
 * employee number — and because the office may not have one to hand
 * when it creates an account. Unique so the same number cannot be
 * issued twice, which is the only reason to record it at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_id', 40)->nullable()->after('phone');

            // Named explicitly: the generated name is fine here, but the
            // project has already been bitten by MySQL's 64-character
            // identifier limit and naming them is now the habit.
            $table->unique('employee_id', 'users_employee_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_employee_id_unique');
            $table->dropColumn('employee_id');
        });
    }
};
