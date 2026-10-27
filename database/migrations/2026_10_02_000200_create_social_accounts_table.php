<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Google or Facebook account signs in as which Visit Dasol account.
 *
 * A table rather than two columns on users, because one person may well use
 * Google on their laptop and Facebook on their phone and expect to land in
 * the same account.
 *
 * The provider's own id is what is matched on, never the email: an email
 * address can be changed at the provider, and matching on it would mean the
 * account followed whoever held the address last.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('provider', 20);
            // Long, because these are opaque strings from somebody else's
            // system and nothing here should assume a length.
            $table->string('provider_id', 191);

            // What the provider called them, kept for the office to recognise
            // an account by. Never shown to the public.
            $table->string('provider_email', 255)->nullable();
            $table->string('provider_name', 191)->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            // One Google account cannot sign in as two different people.
            $table->unique(['provider', 'provider_id']);
            // And one person cannot link the same provider twice.
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
