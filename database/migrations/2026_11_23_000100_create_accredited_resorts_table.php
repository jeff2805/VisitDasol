<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Municipal Tourism Office's official list of accredited resorts.
 *
 * This is a REFERENCE list, uploaded from the office's own spreadsheet. It
 * is deliberately not the same thing as a resort listing on the portal:
 * a resort can be accredited and never register here, and anyone can
 * register here without being accredited. Keeping the two apart is what
 * lets the office replace its list without touching a single application.
 *
 * Nothing in this table is written by a resort owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accredited_resorts', function (Blueprint $table) {
            $table->id();

            $table->string('resort_name', 200);
            $table->string('owner_name', 200)->nullable();

            /*
             * A plain string, not a foreign key to a barangays table,
             * because this system has no barangays table: resorts.barangay
             * is itself a string. A lookup table invented here would have
             * to be kept in step with free text written elsewhere, and the
             * two would drift.
             */
            $table->string('barangay', 120)->nullable();

            $table->string('accreditation_no', 60)->nullable();
            $table->string('accreditation_status', 20)->default('active');
            $table->date('valid_until')->nullable();

            /*
             * The matcher compares against these rather than the display
             * columns above. Stored rather than computed per query so that
             * matching one application does not normalise the whole table,
             * and so the same normalisation is provably used on both sides.
             */
            $table->string('name_key', 200)->index();
            $table->string('owner_key', 200)->nullable()->index();
            $table->string('barangay_key', 120)->nullable()->index();

            // Who uploaded it, kept even if that account is later removed:
            // the audit question is "which office account", and a null
            // answer is more honest than a deleted row taking the fact away.
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            /*
             * An accreditation number identifies one accreditation, so it
             * cannot appear twice. Nullable, because an office list may
             * arrive without the column filled in, and MySQL allows any
             * number of NULLs under a unique index.
             */
            $table->unique('accreditation_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accredited_resorts');
    }
};
