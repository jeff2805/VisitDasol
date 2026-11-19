<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes now serve two jobs, so a row has to say which it is for.
 *
 * Until now the table held exactly one kind of code — the one that confirms a
 * new account's email address — and user_id alone was unique. Signing in asks
 * for a code too, and the two must be able to be outstanding at the same time
 * without replacing each other: somebody half way through confirming their
 * address who then signs in would otherwise destroy one code by asking for
 * the other, and be stuck.
 *
 * The unique key therefore moves from (user_id) to (user_id, purpose): still
 * one outstanding code per account PER JOB, which is what the service relies
 * on when it replaces a code rather than piling them up.
 *
 * Existing rows are all registration codes, which is what the default says.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_verification_codes', function (Blueprint $table): void {
            if (! Schema::hasColumn('email_verification_codes', 'purpose')) {
                $table->string('purpose', 20)->default('registration')->after('user_id');
            }
        });

        /*
         * Dropped and replaced in its own statement, because the unique index
         * on user_id is what the old table was built around and a driver will
         * not let a column take part in two conflicting unique keys at once.
         *
         * The foreign key has to come off first on MySQL: it sits on top of
         * the index, and dropping the index out from under it is refused.
         */
        Schema::table('email_verification_codes', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropUnique('email_verification_codes_user_id_unique');
        });

        Schema::table('email_verification_codes', function (Blueprint $table): void {
            $table->unique(['user_id', 'purpose'], 'email_verification_codes_user_purpose_unique');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        /*
         * Going back means one code per account again, so anything beyond the
         * first for each account has to go or the old unique key cannot be
         * created. Sign-in codes are the newer kind and are the ones dropped;
         * they are short-lived by nature and nobody loses anything but a
         * code they can ask for again.
         */
        \DB::table('email_verification_codes')->where('purpose', 'login')->delete();

        Schema::table('email_verification_codes', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropUnique('email_verification_codes_user_purpose_unique');
        });

        Schema::table('email_verification_codes', function (Blueprint $table): void {
            $table->unique('user_id', 'email_verification_codes_user_id_unique');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('email_verification_codes', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });
    }
};
