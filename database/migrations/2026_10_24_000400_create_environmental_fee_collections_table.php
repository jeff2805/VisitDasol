<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One environmental fee actually taken at a checkpoint.
 *
 * NO LINK TO A BOOKING OR A RESORT, deliberately and permanently. This
 * is municipal money collected at a gate; it is not resort income, it
 * is not part of a room bill, and a foreign key to either would be the
 * first step towards treating it as one. The separation is the point.
 *
 * WHAT THIS ROW IS NOT: it is not proof that a payment cleared. The
 * portal has no gateway for municipal fees, so a row here records that
 * a collector wrote a receipt — nothing more. Remittance and deposit
 * are separate events this table does not claim to know about.
 *
 * The rate and scope are COPIED from the policy, the way a booking
 * copies its room rate. An office that changes the fee next month must
 * not change what last month's receipts say.
 *
 * Nothing is ever edited or deleted. A mistake is voided with a reason,
 * and a correction is a new row pointing back at what it replaces, so
 * the trail of what was taken and what was put right stays whole.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environmental_fee_collections', function (Blueprint $table): void {
            $table->id();

            // The system's own identifier, and the paper one. Both unique:
            // a receipt number used twice is the oldest way to lose money.
            $table->string('reference', 30)->unique();
            $table->string('receipt_number', 50)->unique();

            $table->foreignId('checkpoint_id')
                ->constrained('environmental_fee_checkpoints')
                ->restrictOnDelete();

            $table->foreignId('collector_id')->constrained('users')->restrictOnDelete();

            $table->foreignId('policy_id')
                ->constrained('environmental_fee_policies')
                ->restrictOnDelete();

            // What the policy said on the day, copied here.
            $table->decimal('rate', 10, 2);
            $table->string('scope', 30);
            $table->char('currency', 3)->default('PHP');

            // rate x quantity, worked out on the server.
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('amount', 12, 2);

            // Only what the municipality can actually take at a gate.
            // No online method until a real integration exists.
            $table->string('payment_method', 20)->default('cash');

            /*
             * dateTime, NOT timestamp, and the difference matters.
             *
             * MySQL gives the first NOT NULL TIMESTAMP column in a table
             * an implicit ON UPDATE CURRENT_TIMESTAMP. Voiding a receipt
             * would then silently rewrite when it was collected — the
             * municipal record would say the money was taken at the
             * moment somebody cancelled it. Caught by the report test,
             * which found a 2030 collection dated today.
             */
            $table->dateTime('collected_at');
            $table->text('notes')->nullable();

            // recorded | voided | corrected
            $table->string('status', 20)->default('recorded');

            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();

            // A correction points back at the row it replaces.
            $table->foreignId('corrects_id')->nullable()
                ->constrained('environmental_fee_collections')
                ->nullOnDelete();

            $table->timestamps();

            /*
             * Reports: by day, by checkpoint, by collector. Named by
             * hand, because MySQL caps an identifier at 64 characters
             * and this table's name eats most of them.
             */
            $table->index(['status', 'collected_at'], 'env_fee_status_date_index');
            $table->index(['checkpoint_id', 'collected_at'], 'env_fee_checkpoint_date_index');
            $table->index(['collector_id', 'collected_at'], 'env_fee_collector_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environmental_fee_collections');
    }
};
