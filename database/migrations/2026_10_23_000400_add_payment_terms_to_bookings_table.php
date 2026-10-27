<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The payment terms a reservation was actually made under.
 *
 * Snapshotted for the same reason unit_price is: an owner who raises
 * their deposit next month must not find that every guest who booked
 * last month now owes more. The terms a guest agreed to are the terms
 * that stand.
 *
 * amount_due_now is NULLABLE on purpose. A booking made before this
 * feature existed has no snapshot, and null means exactly that — its
 * terms were "the whole amount", which is how the portal behaved then.
 * Defaulting it to zero would have quietly told every past guest they
 * owed nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            if (! Schema::hasColumn('bookings', 'payment_requirement')) {
                $table->string('payment_requirement', 20)->nullable();
            }

            if (! Schema::hasColumn('bookings', 'payment_deposit_percent')) {
                $table->unsignedTinyInteger('payment_deposit_percent')->default(0);
            }

            if (! Schema::hasColumn('bookings', 'amount_due_now')) {
                $table->decimal('amount_due_now', 12, 2)->nullable();
            }

            if (! Schema::hasColumn('bookings', 'payment_due_at')) {
                $table->timestamp('payment_due_at')->nullable();
            }

            if (! Schema::hasColumn('bookings', 'payment_due_reference')) {
                $table->string('payment_due_reference', 20)->nullable();
            }

            if (! Schema::hasColumn('bookings', 'payment_deadline_hours')) {
                $table->unsignedSmallInteger('payment_deadline_hours')->default(0);
            }

            // Whether the resort's last-minute rule decided these terms.
            if (! Schema::hasColumn('bookings', 'payment_last_minute')) {
                $table->boolean('payment_last_minute')->default(false);
            }

            if (! Schema::hasColumn('bookings', 'payment_on_site_allowed')) {
                $table->boolean('payment_on_site_allowed')->default(true);
            }

            if (! Schema::hasColumn('bookings', 'payment_policy_version')) {
                $table->unsignedInteger('payment_policy_version')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                'payment_requirement',
                'payment_deposit_percent',
                'amount_due_now',
                'payment_due_at',
                'payment_due_reference',
                'payment_deadline_hours',
                'payment_last_minute',
                'payment_on_site_allowed',
                'payment_policy_version',
            ], fn ($column) => Schema::hasColumn('bookings', $column)));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
