<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paying the environmental fee by GCash, and checking that it arrived.
 *
 * TWO DIFFERENT QUESTIONS, now kept on two different columns:
 *
 *   status          what the RECORD is: recorded, voided, corrected.
 *   payment_status  what the MONEY is: received, awaiting_verification,
 *                   verified, rejected.
 *
 * They were one column while everything was cash, because cash handed
 * over at a gate is received the moment the receipt is written. GCash
 * is not: the portal has no connection to anybody's wallet and cannot
 * see a transfer land. Somebody has to open the GCash app and say so.
 *
 * Until they do, the money is NOT counted in any total. A municipality
 * reporting unverified transfers as collected would be reporting money
 * it may never have been sent.
 *
 * Existing rows are cash and are backfilled as received, which is what
 * they always were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environmental_fee_collections', function (Blueprint $table): void {
            if (! Schema::hasColumn('environmental_fee_collections', 'payment_status')) {
                $table->string('payment_status', 30)->default('received')->after('payment_method');
            }

            // What the payer typed off their GCash receipt. Unique, so one
            // transfer cannot be claimed against two fees. Nullable, and
            // MySQL allows many NULLs in a unique index, so cash rows are
            // unaffected.
            if (! Schema::hasColumn('environmental_fee_collections', 'gcash_reference')) {
                $table->string('gcash_reference', 80)->nullable()->unique();
            }

            if (! Schema::hasColumn('environmental_fee_collections', 'gcash_sender')) {
                $table->string('gcash_sender', 120)->nullable();
            }

            if (! Schema::hasColumn('environmental_fee_collections', 'proof_image')) {
                $table->string('proof_image')->nullable();
            }

            // Who opened the GCash app and confirmed it, and when.
            if (! Schema::hasColumn('environmental_fee_collections', 'verified_by')) {
                $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('environmental_fee_collections', 'verified_at')) {
                $table->dateTime('verified_at')->nullable();
            }

            if (! Schema::hasColumn('environmental_fee_collections', 'verification_note')) {
                $table->text('verification_note')->nullable();
            }
        });

        Schema::table('environmental_fee_collections', function (Blueprint $table): void {
            $table->index(['payment_status', 'collected_at'], 'env_fee_payment_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('environmental_fee_collections', function (Blueprint $table): void {
            $table->dropIndex('env_fee_payment_status_index');

            if (Schema::hasColumn('environmental_fee_collections', 'verified_by')) {
                $table->dropConstrainedForeignId('verified_by');
            }

            $columns = array_values(array_filter([
                'payment_status', 'gcash_reference', 'gcash_sender',
                'proof_image', 'verified_at', 'verification_note',
            ], fn ($column) => Schema::hasColumn('environmental_fee_collections', $column)));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
