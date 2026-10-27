<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Narrows the room categories to budget, premium and cottage.
 *
 * The list used to carry standard, deluxe, family, villa and other as well.
 * Resort owners around Dasol do not describe their rooms that way: the resorts
 * here let a cheap room, a good room, or a cottage, and a longer list only
 * gave owners more ways to file a room wrongly.
 *
 * A row still holding a removed value would make RoomType throw the moment
 * anything read it — a resort page that simply breaks — so the data is
 * brought along with the code rather than left behind it.
 *
 * Everything removed becomes "budget", not because every such room is cheap,
 * but because it is the claim that cannot mislead a visitor into expecting
 * more than they get. Owners can change it in one click on their room form.
 */
return new class extends Migration
{
    /** value => what it becomes. */
    private const RETIRED = [
        'standard' => 'budget',
        'deluxe' => 'premium',
        'family' => 'cottage',
        'villa' => 'cottage',
        'other' => 'budget',
    ];

    public function up(): void
    {
        foreach (self::RETIRED as $was => $becomes) {
            DB::table('rooms')->where('room_type', $was)->update(['room_type' => $becomes]);
        }
    }

    /**
     * Nothing to undo.
     *
     * The original category of each room is not recorded anywhere, so putting
     * the old names back would mean inventing which room had which — and a
     * guess written into somebody's listing is worse than leaving it as it is.
     * Restoring the enum cases is enough to make the old values storable again.
     */
    public function down(): void
    {
        // Deliberately empty. See above.
    }
};
