<?php

namespace App\Console\Commands;

use App\Models\EmailVerificationCode;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Throws away one-time codes that can no longer be used.
 *
 * A code is dead the moment it expires, but nothing deleted it, so the table
 * grew by one row per sign-up for ever. They are only hashes, but a dead
 * credential is still a credential: the tidiest thing to do with one is to
 * stop keeping it.
 */
class PurgeExpiredCodes extends Command
{
    protected $signature = 'visitdasol:purge-codes {--days=1 : Keep expired codes this many days first}';

    protected $description = 'Delete one-time email codes that have expired';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(0, (int) $this->option('days')));

        $deleted = EmailVerificationCode::query()
            ->where('expires_at', '<', $cutoff)
            ->delete();

        $this->info($deleted === 0
            ? 'No expired codes to remove.'
            : "Removed {$deleted} expired ".Str::plural('code', $deleted).'.');

        return self::SUCCESS;
    }
}
