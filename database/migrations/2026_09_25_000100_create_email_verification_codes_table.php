<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes that confirm a new account owns the email address it gave.
 *
 * A row exists only while a code is outstanding. It is removed the moment the
 * code is accepted, replaced by a resend, or thrown away after too many wrong
 * guesses. The code itself is never stored, only its hash, so a copy of this
 * table is of no use to anyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_verification_codes', function (Blueprint $table) {
            $table->id();
            // One outstanding code per account: a resend replaces the old row.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            // dateTime, not timestamp: MariaDB gives only the first TIMESTAMP
            // column in a table an automatic default, and rejects the rest
            // under strict mode. Both values are always written by the service.
            $table->dateTime('expires_at');
            $table->dateTime('last_sent_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_codes');
    }
};
