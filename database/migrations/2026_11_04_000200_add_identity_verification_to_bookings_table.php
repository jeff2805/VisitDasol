<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The guest's valid ID, and the two moments it is checked against: arrival
 * and departure.
 *
 * ON THE BOOKING, NOT A TABLE OF ITS OWN. One stay asks for one ID from one
 * guest, and the resort has to be able to answer "has this party been
 * checked in?" from the reservation in front of them. A side table would put
 * a join between the owner and that answer and would let two rows disagree
 * about it.
 *
 * id_document_path is a key on the PRIVATE disk (storage/app/private), never
 * a URL. Nothing here is servable by Apache; the only way to the bytes is
 * through a route that authorises first. The column holds the path and
 * nothing else — no ID number, no name off the document, nothing that would
 * leak by reading the table.
 *
 * checked_in_by / checked_out_by name the staff member who did it, which is
 * what makes these records rather than flags.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            if (! Schema::hasColumn('bookings', 'id_document_path')) {
                $table->string('id_document_path')->nullable();
            }

            if (! Schema::hasColumn('bookings', 'id_document_status')) {
                $table->string('id_document_status', 20)->default('not_submitted');
            }

            if (! Schema::hasColumn('bookings', 'id_document_submitted_at')) {
                $table->timestamp('id_document_submitted_at')->nullable();
            }

            if (! Schema::hasColumn('bookings', 'id_document_verified_at')) {
                $table->timestamp('id_document_verified_at')->nullable();
            }

            if (! Schema::hasColumn('bookings', 'id_document_rejection_reason')) {
                $table->text('id_document_rejection_reason')->nullable();
            }

            if (! Schema::hasColumn('bookings', 'checked_in_at')) {
                $table->timestamp('checked_in_at')->nullable();
            }

            if (! Schema::hasColumn('bookings', 'checked_out_at')) {
                $table->timestamp('checked_out_at')->nullable();
            }
        });

        /*
         * The three staff references, in a pass of their own.
         *
         * foreignId()->constrained() issues its own ALTER on some drivers,
         * and mixing that with the plain column adds above has been known to
         * reorder badly on MySQL. Separated so each is a clean statement,
         * and guarded so a part-applied migration can be re-run.
         */
        foreach ([
            'id_document_verified_by',
            'checked_in_by',
            'checked_out_by',
        ] as $column) {
            if (Schema::hasColumn('bookings', $column)) {
                continue;
            }

            Schema::table('bookings', function (Blueprint $table) use ($column): void {
                $table->foreignId($column)
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        Schema::table('bookings', function (Blueprint $table): void {
            // The owner's queue: "whose ID is waiting on me, at my resort".
            $table->index(['resort_id', 'id_document_status'], 'bookings_resort_id_doc_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex('bookings_resort_id_doc_status_index');
        });

        foreach ([
            'id_document_verified_by',
            'checked_in_by',
            'checked_out_by',
        ] as $column) {
            if (! Schema::hasColumn('bookings', $column)) {
                continue;
            }

            Schema::table('bookings', function (Blueprint $table) use ($column): void {
                $table->dropConstrainedForeignId($column);
            });
        }

        Schema::table('bookings', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                'id_document_path',
                'id_document_status',
                'id_document_submitted_at',
                'id_document_verified_at',
                'id_document_rejection_reason',
                'checked_in_at',
                'checked_out_at',
            ], fn (string $column) => Schema::hasColumn('bookings', $column)));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
