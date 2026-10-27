<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The municipality's environmental fee, as configured by the tourism
 * office. A MUNICIPAL collection, entirely separate from what a resort
 * charges for a room.
 *
 * THE MODULE STARTS OFF AND UNPRICED, and that is deliberate:
 *
 *   enabled  false. Nothing may be collected until somebody with the
 *            authority to do so turns it on, and the row records who
 *            and when.
 *   amount   NULL, not zero. "Nobody has set a rate" and "the rate is
 *            nothing" are different facts, and a municipal system must
 *            not confuse them. A fee cannot be charged against a null.
 *
 * No rate, no scope and no exemption is written into this project. Those
 * come from an ordinance, and this table is only where the office
 * records what the ordinance says.
 *
 * Each save is a ROW, not an edit, so the configuration history is the
 * table itself. Two enabled policies may never cover the same day; the
 * request enforces that, because an ambiguous rate is a rate nobody can
 * defend to a visitor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environmental_fee_policies', function (Blueprint $table): void {
            $table->id();

            // Off until an authorised officer says otherwise.
            $table->boolean('enabled')->default(false);

            // NULL until an ordinance gives a figure. Never defaulted.
            $table->decimal('amount', 10, 2)->nullable();
            $table->char('currency', 3)->default('PHP');

            // What the amount is charged against: per_visitor, per_group,
            // per_vehicle. What a given ordinance actually says is the
            // office's to record.
            $table->string('scope', 30)->nullable();

            // When it may be collected. Null until configured.
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();

            // The ordinance or resolution this implements, in the office's
            // own words. Free text on purpose: this project does not model
            // Philippine legislation.
            $table->string('authority_reference', 150)->nullable();
            $table->text('notes')->nullable();

            // Who configured it, and who switched it on.
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('enabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enabled_at')->nullable();

            $table->timestamps();

            /*
             * Finding the policy in force on a given day. Named by hand:
             * MySQL caps an identifier at 64 characters and the one
             * Laravel would generate from these three columns is longer.
             */
            $table->index(
                ['enabled', 'effective_from', 'effective_until'],
                'env_fee_policies_in_force_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environmental_fee_policies');
    }
};
