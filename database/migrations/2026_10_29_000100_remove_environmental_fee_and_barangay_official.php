<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the municipal environmental fee module and the barangay
 * official role.
 *
 * WHY THIS IS A NEW MIGRATION RATHER THAN A DELETED ONE. The migrations
 * that created these tables are left exactly where they are. They are
 * the record of what was actually applied to a real database, and
 * editing that record to say something else never happened would make
 * every other migration less trustworthy. This one says plainly what
 * was removed and when.
 *
 * WHAT IS DESTROYED, AND WHAT IS NOT.
 *
 * Destroyed: the six environmental fee tables, users.employee_id,
 * users.barangay, and the four destination review columns. Everything
 * in them was copied first to
 * storage/app/backups/removed-environmental-fee-and-barangay-official-*.json.
 *
 * NOT destroyed:
 *   - No user account is deleted. The accounts that held a retired role
 *     become tourists, which is the least any account can do, and they
 *     keep their name, email, password and history.
 *   - No activity_logs row is deleted. The trail is append-only. Rows
 *     that pointed at a fee record keep every word of what happened;
 *     only the pointer itself is folded into properties, because a
 *     morph type naming a class that no longer exists is not merely
 *     dead — Eloquent fatals on it the moment the log viewer opens.
 *   - No destination is deleted.
 *
 * Every step is guarded, so this is safe on a database that never had
 * these tables (a fresh test database runs the creates and then this).
 */
return new class extends Migration
{
    /** Child tables before parents: nothing is left pointing at nothing. */
    private const FEE_TABLES = [
        'environmental_fee_remittances',
        'environmental_fee_collections',
        'environmental_fee_collectors',
        'environmental_fee_checkpoints',
        'environmental_fee_policies',
        'environmental_fee_accounts',
    ];

    public function up(): void
    {
        $this->retireRoles();
        $this->keepTheAuditTrailReadable();
        $this->settleDestinationStatuses();
        $this->dropDestinationReviewColumns();
        $this->dropUserColumns();
        $this->dropFeeTables();
    }

    /**
     * Accounts whose post no longer exists become tourists.
     *
     * NOT DELETED, AND NOT DEACTIVATED. A person who collected a fee at
     * a barrier or wrote up their barangay's waterfalls still has an
     * account, a password they chose, and whatever else they did here.
     * Tourist is simply the role with no privileges attached, which is
     * the honest answer to "what is this account now".
     */
    private function retireRoles(): void
    {
        DB::table('users')
            ->whereIn('role', ['barangay_official', 'fee_collector', 'cashier'])
            ->update(['role' => 'tourist']);
    }

    /**
     * Fold a retired subject pointer into the entry's own properties.
     *
     * The row stays, with its action, its description, its figures, its
     * author and its timestamp. What goes is subject_type/subject_id,
     * which named a model this application no longer has — and which
     * would fatal the activity log page rather than merely show nothing.
     *
     * The pair is written into properties under retired_subject, so an
     * auditor reading the trail can still see exactly which record the
     * entry was about.
     */
    private function keepTheAuditTrailReadable(): void
    {
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        $dangling = DB::table('activity_logs')
            ->where('subject_type', 'like', 'environmental_fee%')
            ->get(['id', 'subject_type', 'subject_id', 'properties']);

        foreach ($dangling as $row) {
            $properties = json_decode((string) $row->properties, true);

            if (! is_array($properties)) {
                $properties = [];
            }

            $properties['retired_subject'] = [
                'type' => $row->subject_type,
                'id' => $row->subject_id,
                'note' => 'The environmental fee module was removed; this record no longer exists.',
            ];

            DB::table('activity_logs')->where('id', $row->id)->update([
                'subject_type' => null,
                'subject_id' => null,
                'properties' => json_encode($properties),
            ]);
        }
    }

    /**
     * A destination can only be published or hidden now.
     *
     * "pending" and "rejected" existed for submissions waiting on the
     * office. Anything still sitting in one of those becomes hidden
     * rather than published: a spot nobody approved must not go public
     * because the queue that would have reviewed it was removed.
     */
    private function settleDestinationStatuses(): void
    {
        if (! Schema::hasTable('destinations')) {
            return;
        }

        DB::table('destinations')
            ->whereIn('status', ['pending', 'rejected'])
            ->update(['status' => 'inactive']);
    }

    private function dropDestinationReviewColumns(): void
    {
        if (! Schema::hasTable('destinations')) {
            return;
        }

        /*
         * reviewed_by carries a foreign key to users, so it needs
         * dropConstrainedForeignId rather than a plain dropColumn.
         */
        if (Schema::hasColumn('destinations', 'reviewed_by')) {
            Schema::table('destinations', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('reviewed_by');
            });
        }

        $columns = array_values(array_filter(
            ['submitted_at', 'reviewed_at', 'review_remarks'],
            fn (string $column) => Schema::hasColumn('destinations', $column)
        ));

        if ($columns !== []) {
            Schema::table('destinations', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    private function dropUserColumns(): void
    {
        // barangay said which barangay an official spoke for; employee_id
        // was the fee staff's municipal employee number. Both are
        // meaningless without the roles they belonged to.
        $columns = array_values(array_filter(
            ['barangay', 'employee_id'],
            fn (string $column) => Schema::hasColumn('users', $column)
        ));

        if ($columns === []) {
            return;
        }

        /*
         * employee_id carries a unique index. MySQL drops an index with
         * its column, but naming it keeps this readable and makes the
         * intent explicit if the column is ever reinstated.
         */
        Schema::table('users', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }

    private function dropFeeTables(): void
    {
        foreach (self::FEE_TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * There is no going back from this one.
     *
     * The tables could be recreated empty, but the receipts, the
     * checkpoints and the rate that were in them cannot be conjured
     * back, and a down() that silently produced an empty module would
     * be a worse lie than refusing. The JSON backup named at the top of
     * this file is the way back, and it is a deliberate act.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'Removing the environmental fee module and the barangay official role '
            .'cannot be rolled back automatically. The data was copied to '
            .'storage/app/backups/removed-environmental-fee-and-barangay-official-*.json '
            .'before it was dropped; restore from there deliberately.'
        );
    }
};
