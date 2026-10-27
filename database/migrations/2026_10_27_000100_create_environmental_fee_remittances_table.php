<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Handing the money over.
 *
 * Until now the module recorded that a collector wrote a receipt at a
 * gate and stopped there — every screen said so in as many words, and
 * said that remittance was handled the way it always was. This is the
 * step that was missing: the collector declares what they are turning
 * in, the office counts it, and the two figures are compared.
 *
 * WHAT A ROW HERE IS, AND IS NOT
 *
 * It is a record of a declaration and of a count made by a named person
 * at a named time. It is NOT a bank deposit, not a cleared payment, and
 * not proof that money reached the treasury — this portal cannot see a
 * bank account or a wallet, and a row that claimed otherwise would be a
 * lie the audit trail then preserves.
 *
 * THREE FIGURES, NEVER ONE
 *
 *   expected_amount  what the receipts in the period add up to, worked
 *                    out by the server from the collections themselves
 *   declared_amount  what the collector says they are handing over
 *   counted_amount   what the office actually counted, filled in on review
 *
 * They are stored separately and never overwritten by each other. The
 * difference between them is the whole point of a reconciliation: a
 * shortfall that quietly disappears into a single "amount" column is a
 * shortfall nobody can investigate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environmental_fee_remittances', function (Blueprint $table) {
            $table->id();

            // Human-quotable, like a collection's. Printed on the slip
            // and quoted in a dispute.
            $table->string('reference', 30)->unique();

            $table->foreignId('checkpoint_id')
                ->constrained('environmental_fee_checkpoints')
                ->restrictOnDelete();

            $table->foreignId('collector_id')
                ->constrained('users')
                ->restrictOnDelete();

            /*
             * The period being turned in, as whole dates. A remittance
             * covers "these days at this gate", and the collections it
             * answers for are found by that range rather than being
             * listed one by one — a list would go stale the moment a
             * receipt in the period was voided.
             */
            $table->date('period_start');
            $table->date('period_end');

            /*
             * Money. All three in the same currency as the collections,
             * all nullable where they have not happened yet.
             *
             * expected is frozen at submission so the slip can be
             * reprinted and still say what was claimed at the time;
             * the live figure is recalculated on the review screen and
             * any drift between them is shown rather than hidden.
             */
            $table->decimal('expected_amount', 12, 2);
            $table->unsignedInteger('expected_receipts');
            $table->decimal('declared_amount', 12, 2);
            $table->decimal('counted_amount', 12, 2)->nullable();

            $table->char('currency', 3)->default('PHP');

            $table->string('status', 20)->default('submitted');

            /*
             * dateTime, not timestamp. MySQL gives the first NOT NULL
             * TIMESTAMP column in a table an implicit
             * ON UPDATE CURRENT_TIMESTAMP, which silently rewrites the
             * moment a remittance was handed over every time the row is
             * touched — exactly the bug this project already hit on
             * collections.collected_at.
             */
            $table->dateTime('submitted_at');

            $table->foreignId('reviewed_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();

            // Why the office accepted a figure that did not match, or
            // why it refused one. Required by the request when there is
            // a discrepancy; a gap with no explanation is the thing an
            // auditor cannot work with.
            $table->text('collector_note')->nullable();
            $table->text('review_note')->nullable();

            $table->timestamps();

            /*
             * Named explicitly. MySQL caps an identifier at 64
             * characters and the generated name for this table plus
             * three columns runs past it.
             */
            $table->index(['status', 'submitted_at'], 'env_remit_status_date_index');
            $table->index(['checkpoint_id', 'period_start'], 'env_remit_checkpoint_period_index');
            $table->index(['collector_id', 'period_start'], 'env_remit_collector_period_index');

            /*
             * One open remittance per collector, per gate, per period.
             * Without this, pressing submit twice turns one night's
             * takings into two liabilities.
             */
            $table->unique(
                ['checkpoint_id', 'collector_id', 'period_start', 'period_end'],
                'env_remit_one_per_period_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environmental_fee_remittances');
    }
};
