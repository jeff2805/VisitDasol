<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who may take money at which checkpoint.
 *
 * NOT A ROLE. This project has four roles — tourist, resort owner,
 * barangay official, municipal tourism office — and inventing a fifth
 * would be inventing a municipal post that may not exist. Authority to
 * collect comes from a ROW here: the office names a person and a
 * checkpoint, and that pairing is the permission.
 *
 * It also makes the permission auditable and revocable one checkpoint
 * at a time, which a role could not do: somebody trusted at the
 * Tambobong gate is not thereby trusted at every gate in Dasol.
 *
 * Withdrawn by clearing active, never by deleting: collections point at
 * the collector, and the record of who took the money has to survive
 * the end of their assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environmental_fee_collectors', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('checkpoint_id')
                ->constrained('environmental_fee_checkpoints')
                ->cascadeOnDelete();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->boolean('active')->default(true);

            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // One assignment per person per checkpoint.
            $table->unique(['checkpoint_id', 'user_id']);
            $table->index(['user_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environmental_fee_collectors');
    }
};
